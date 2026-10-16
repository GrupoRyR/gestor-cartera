<?php

namespace App\Modules\Comisiones\Domain\Settlement;

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Shared\Period;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One version of the settlement of a period and its step in the monthly
 * flow (§6 steps 4–7):
 *
 *   calculate               → draft, version 1, revision 1
 *   draft or approved       → recalculate → draft, same version, next revision (approval and justifications cleared)
 *   draft                   → approve     → approved (the reviewed revision, another user, every blocking alert justified)
 *   approved                → close       → closed (frozen)
 *   closed                  → complement  → draft, next version, revision 1, with a reason
 *
 * The revision counts the calculations of a version. The approver names the
 * revision they reviewed, so a recalculation between the review and the
 * approval is never approved unseen (§6 step 5, §10).
 *
 * Immutable: each step returns a new instance and leaves the old one as it
 * was, so a closed version is never overwritten (§6 step 7). The result
 * carries the parameters it was calculated with (§4), so a stored version
 * never depends on later parameter changes.
 *
 * The constructor rebuilds a stored version and rejects any state the flow
 * cannot reach; use the steps above to move one. User names are compared
 * trimmed and case-insensitive; reasons and justifications are kept trimmed.
 */
final readonly class Settlement
{
    /**
     * @param  int  $version  1 for the settlement of the period, one more per complementary settlement.
     * @param  int  $revision  1 when the version is calculated, one more per recalculation; the approval
     *                         and the closing keep it.
     * @param  string  $calculatedBy  The analyst who calculated this version last.
     * @param  string|null  $reason  Why the complementary version was registered; null for version 1.
     * @param  int|null  $previousVersion  The closed version a complementary one corrects; null for version 1.
     * @param  array<string, string>  $justifications  Alert key → justification of every blocking alert,
     *                                                 given at the approval (§9.7); empty in a draft.
     *
     * @throws InvalidArgumentException when the state cannot be reached through the flow.
     * @throws SegregationOfDuties when the approver is the analyst who calculated it.
     */
    public function __construct(
        public Period $period,
        public int $version,
        public int $revision,
        public SettlementStatus $status,
        public SettlementResult $result,
        public string $calculatedBy,
        public DateTimeImmutable $calculatedAt,
        public ?string $approvedBy = null,
        public ?DateTimeImmutable $approvedAt = null,
        public ?string $closedBy = null,
        public ?DateTimeImmutable $closedAt = null,
        public ?string $reason = null,
        public ?int $previousVersion = null,
        public array $justifications = [],
    ) {
        if ($version < 1) {
            throw new InvalidArgumentException("A settlement version starts at 1, got {$version}.");
        }

        if ($revision < 1) {
            throw new InvalidArgumentException("A settlement revision starts at 1, got {$revision}.");
        }

        if (! $result->period()->equals($period)) {
            throw new InvalidArgumentException(sprintf(
                'A %s result cannot be stored in the %s settlement.',
                $result->period()->key(),
                $period->key(),
            ));
        }

        self::assertUser($calculatedBy, 'calculated');
        self::assertLineage($version, $previousVersion, $reason);
        $this->assertSteps();
    }

    /** Calculates the settlement of a period for the first time: draft version 1, revision 1 (§6 step 4). */
    public static function calculate(SettlementResult $result, string $analyst, DateTimeImmutable $at): self
    {
        return new self($result->period(), 1, 1, SettlementStatus::Draft, $result, $analyst, $at);
    }

    /**
     * Replaces the result while the period is open (§6 step 4), as the next
     * revision of the same version. An approved settlement goes back to
     * draft: the new figures need a new approval.
     *
     * @throws SettlementLocked when this version is closed.
     * @throws InvalidArgumentException when the result belongs to another period.
     */
    public function recalculate(SettlementResult $result, string $analyst, DateTimeImmutable $at): self
    {
        if ($this->status === SettlementStatus::Closed) {
            throw SettlementLocked::closed($this->period, $this->version);
        }

        return new self(
            period: $this->period,
            version: $this->version,
            revision: $this->revision + 1,
            status: SettlementStatus::Draft,
            result: $result,
            calculatedBy: $analyst,
            calculatedAt: $at,
            reason: $this->reason,
            previousVersion: $this->previousVersion,
        );
    }

    /**
     * Approves a draft (§6 step 5): it is still at the revision the approver
     * reviewed, the approver is not the analyst who calculated it (§10) and
     * every blocking alert of the result has a justification (§9.7). Warnings
     * need none.
     *
     * @param  int  $reviewedRevision  The revision the approver reviewed (`$settlement->revision` when shown).
     * @param  array<string, string>  $justifications  Alert key ("codigo_desconocido:99") → justification.
     *
     * @throws InvalidTransition when this version is not a draft.
     * @throws StaleSettlement when it was recalculated after the review: the revisions differ.
     * @throws SegregationOfDuties when the approver calculated it.
     * @throws InvalidArgumentException when the approver is blank or a key is not a blocking alert of the result.
     * @throws UnresolvedAlerts when a blocking alert has no justification, or a blank one.
     */
    public function approve(string $approver, DateTimeImmutable $at, int $reviewedRevision, array $justifications = []): self
    {
        if ($this->status !== SettlementStatus::Draft) {
            throw InvalidTransition::cannot('approve', $this);
        }

        if ($reviewedRevision !== $this->revision) {
            throw new StaleSettlement($this->period, $this->version, $reviewedRevision, $this->revision);
        }

        self::assertUser($approver, 'approved');
        $this->assertSegregated($approver);

        return new self(
            period: $this->period,
            version: $this->version,
            revision: $this->revision,
            status: SettlementStatus::Approved,
            result: $this->result,
            calculatedBy: $this->calculatedBy,
            calculatedAt: $this->calculatedAt,
            approvedBy: $approver,
            approvedAt: $at,
            reason: $this->reason,
            previousVersion: $this->previousVersion,
            justifications: $this->justified($justifications),
        );
    }

    /**
     * Closes an approved settlement (§6 step 6): from now on it is frozen.
     *
     * @throws InvalidTransition when this version is not approved.
     * @throws InvalidArgumentException when the user is blank.
     */
    public function close(string $user, DateTimeImmutable $at): self
    {
        if ($this->status !== SettlementStatus::Approved) {
            throw InvalidTransition::cannot('close', $this);
        }

        return new self(
            period: $this->period,
            version: $this->version,
            revision: $this->revision,
            status: SettlementStatus::Closed,
            result: $this->result,
            calculatedBy: $this->calculatedBy,
            calculatedAt: $this->calculatedAt,
            approvedBy: $this->approvedBy,
            approvedAt: $this->approvedAt,
            closedBy: $user,
            closedAt: $at,
            reason: $this->reason,
            previousVersion: $this->previousVersion,
            justifications: $this->justifications,
        );
    }

    /**
     * Registers a complementary settlement over this closed one (§6 step 7):
     * a new draft version, at revision 1, with its reason, which goes through
     * approval and closing again. This version stays closed and untouched.
     *
     * @throws InvalidTransition when this version is not closed.
     * @throws InvalidArgumentException when the reason is blank or the result belongs to another period.
     */
    public function complement(string $reason, SettlementResult $result, string $analyst, DateTimeImmutable $at): self
    {
        if ($this->status !== SettlementStatus::Closed) {
            throw InvalidTransition::cannot('complement', $this);
        }

        return new self(
            period: $this->period,
            version: $this->version + 1,
            revision: 1,
            status: SettlementStatus::Draft,
            result: $result,
            calculatedBy: $analyst,
            calculatedAt: $at,
            reason: trim($reason),
            previousVersion: $this->version,
        );
    }

    /** Whether the period can still be recalculated: draft or approved, not closed. */
    public function isOpen(): bool
    {
        return $this->status !== SettlementStatus::Closed;
    }

    /**
     * Every blocking alert of the result with its justification, in alert order.
     *
     * @param  array<string, string>  $justifications
     * @return array<string, string>
     *
     * @throws InvalidArgumentException when a key is not a blocking alert of the result.
     * @throws UnresolvedAlerts when a blocking alert has no justification, or a blank one.
     */
    private function justified(array $justifications): array
    {
        $blocking = array_map(fn (Alert $alert) => $alert->key(), $this->result->blockingAlerts());

        foreach (array_keys($justifications) as $key) {
            if (! in_array($key, $blocking, true)) {
                throw new InvalidArgumentException(sprintf(
                    '%s is not a blocking alert of version %d of the %s settlement; only blocking alerts are justified.',
                    $key,
                    $this->version,
                    $this->period->key(),
                ));
            }
        }

        $justified = [];
        $unresolved = [];

        foreach ($blocking as $key) {
            $text = trim($justifications[$key] ?? '');

            if ($text === '') {
                $unresolved[] = $key;
            } else {
                $justified[$key] = $text;
            }
        }

        if ($unresolved !== []) {
            throw new UnresolvedAlerts($this->period, $this->version, $unresolved);
        }

        return $justified;
    }

    /**
     * The approval and closing fields match the status: a draft has neither,
     * an approved version has its approval only, a closed one has both.
     *
     * @throws InvalidArgumentException|SegregationOfDuties
     */
    private function assertSteps(): void
    {
        if ($this->status === SettlementStatus::Draft) {
            if ($this->approvedBy !== null || $this->approvedAt !== null || $this->closedBy !== null || $this->closedAt !== null) {
                throw new InvalidArgumentException('A draft settlement has no approval or closing.');
            }

            if ($this->justifications !== []) {
                throw new InvalidArgumentException('A draft settlement has no justifications: they are given at the approval.');
            }

            return;
        }

        if ($this->approvedBy === null || $this->approvedAt === null) {
            throw new InvalidArgumentException(sprintf(
                'A settlement that is %s needs the user who approved it and when.',
                strtolower($this->status->name),
            ));
        }

        self::assertUser($this->approvedBy, 'approved');
        $this->assertSegregated($this->approvedBy);

        $closing = $this->closedBy !== null || $this->closedAt !== null;

        if ($this->status === SettlementStatus::Approved && $closing) {
            throw new InvalidArgumentException('Only a closed settlement has the user who closed it and when.');
        }

        if ($this->status === SettlementStatus::Closed) {
            if ($this->closedBy === null || $this->closedAt === null) {
                throw new InvalidArgumentException('A closed settlement needs the user who closed it and when.');
            }

            self::assertUser($this->closedBy, 'closed');
        }
    }

    /** @throws SegregationOfDuties when the approver is the analyst who calculated this version (§10). */
    private function assertSegregated(string $approver): void
    {
        if (mb_strtolower(trim($approver)) === mb_strtolower(trim($this->calculatedBy))) {
            throw SegregationOfDuties::sameAnalyst($this->calculatedBy, $this->period, $this->version);
        }
    }

    /**
     * Version 1 stands alone; a complementary version follows the one before
     * it and says why it was registered.
     *
     * @throws InvalidArgumentException
     */
    private static function assertLineage(int $version, ?int $previousVersion, ?string $reason): void
    {
        $expected = $version === 1 ? null : $version - 1;

        if ($previousVersion !== $expected) {
            throw new InvalidArgumentException($expected === null
                ? 'Version 1 of a settlement has no previous version.'
                : "Version {$version} of a settlement must have {$expected} as its previous version.");
        }

        if ($previousVersion === null && $reason !== null) {
            throw new InvalidArgumentException('Only a complementary settlement has a reason.');
        }

        if ($previousVersion !== null && trim($reason ?? '') === '') {
            throw new InvalidArgumentException("Complementary version {$version} of a settlement needs a reason.");
        }
    }

    /**
     * @param  string  $step  "calculated", "approved" or "closed".
     *
     * @throws InvalidArgumentException when the user is blank.
     */
    private static function assertUser(string $user, string $step): void
    {
        if (trim($user) === '') {
            throw new InvalidArgumentException("A settlement needs the user who {$step} it.");
        }
    }
}
