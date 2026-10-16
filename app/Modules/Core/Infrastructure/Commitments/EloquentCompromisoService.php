<?php

namespace App\Modules\Core\Infrastructure\Commitments;

use App\Modules\Core\Application\Commitments\CommitmentActivity;
use App\Modules\Core\Application\Commitments\CommitmentClientStatus;
use App\Modules\Core\Application\Commitments\CommitmentWriteRule;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Infrastructure\Access\PortfolioScope;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Modules\Core\Infrastructure\Portfolio\ClientReach;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use InvalidArgumentException;

/**
 * CompromisoService over the "commitments" table, for the signed-in user.
 *
 * Reach (docs/arquitectura.md §6.2): find() and every write look for the
 * commitment among the clients within the user's reach in every company
 * they may work in (ClientReach, as GestionLogger does), so a write started
 * before a company switch still finds it; paginate() follows the active
 * company or the group view, or the query's companies the user may see, as
 * CarteraRepository does. Commitments of soft-deleted clients never appear.
 * Out of reach behaves as missing: null, an empty page or RecordNotFound.
 *
 * Writes check, in this order: the reach (RecordNotFound), the write rule of
 * the client's company (CommitmentWriteRule, AuthorizationException), the
 * input (InvalidArgumentException) and the lifecycle (DomainException).
 * Each one runs in a single transaction with the commitment and the client
 * locked: the change, an ActivityType::Commitment entry in the client's log
 * written by the user, an audit entry core.commitment.<verb> and the
 * client's status (CommitmentClientStatus).
 */
final class EloquentCompromisoService implements CompromisoService
{
    /**
     * Amounts stay below 10^16 pesos: decimal(18,2) keeps 16 digits before the point.
     */
    private const float AMOUNT_LIMIT = 1e16;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly CurrentCompany $currentCompany,
        private readonly ClientReach $reach,
        private readonly CommitmentWriteRule $rule,
        private readonly CommitmentClientStatus $clientStatus,
        private readonly CommitmentMapper $mapper,
        private readonly GestionLogger $log,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
    ) {}

    public function create(NewCommitment $commitment): Commitment
    {
        $created = $this->database->transaction(function () use ($commitment): CommitmentRecord {
            $client = $this->reach->scope()->clients(ClientRecord::query())->whereKey($commitment->clientId)->lockForUpdate()->first()
                ?? throw RecordNotFound::for('client', $commitment->clientId);
            $author = $this->rule->authorize($this->auth->guard()->user(), $client);
            $amount = self::amount($commitment->amount);

            $record = CommitmentRecord::query()->create([
                'company' => $client->company,
                'client_id' => $client->id,
                'advisor_code' => $client->advisor_code,
                'amount' => $amount,
                'due_date' => $commitment->dueDate->toDateString(),
                'status' => CommitmentStatus::Pending,
                'notes' => self::text($commitment->notes),
                'created_by' => $author->id,
            ]);

            $this->log->log(CommitmentActivity::created($record));

            $this->audit->record(
                'core.commitment.created',
                'commitment',
                $record->id,
                [
                    'advisor_code' => $record->advisor_code,
                    'amount' => (float) $record->amount,
                    'due_date' => $record->due_date->toDateString(),
                    'notes' => $record->notes,
                ],
                ['client_id' => $client->id],
            );

            $this->clientStatus->afterCreate($client, $record);

            return $record;
        });

        return $this->load($created->id);
    }

    public function update(int $commitmentId, CommitmentChanges $changes): Commitment
    {
        $this->database->transaction(function () use ($commitmentId, $changes): void {
            [$commitment, $client] = $this->lockForWrite($commitmentId);
            $amount = $changes->amount === null ? null : self::amount($changes->amount);

            if ($commitment->status !== CommitmentStatus::Pending) {
                throw new DomainException("Commitment {$commitmentId} is {$commitment->status->value}; only pending commitments change.");
            }

            // Null keeps a field; blank notes keep them too (CommitmentChanges cannot clear them).
            $requested = [
                'amount' => $amount,
                'due_date' => $changes->dueDate?->toDateString(),
                'notes' => self::text($changes->notes),
            ];
            $attributes = [];
            $audited = [];

            foreach ($requested as $field => $value) {
                $old = self::stored($commitment, $field);

                if ($value !== null && $value !== $old) {
                    $attributes[$field] = $value;
                    $audited[$field] = ['old' => self::audited($field, $old), 'new' => self::audited($field, $value)];
                }
            }

            if ($attributes === []) {
                return;
            }

            $commitment->update($attributes);

            $this->log->log(CommitmentActivity::updated($commitment));
            $this->audit->record('core.commitment.updated', 'commitment', $commitment->id, $audited, ['client_id' => $client->id]);
        });

        return $this->load($commitmentId);
    }

    public function markFulfilled(int $commitmentId, ?string $note = null): Commitment
    {
        $this->database->transaction(function () use ($commitmentId, $note): void {
            [$commitment, $client] = $this->lockForWrite($commitmentId);
            self::guardNotFinal($commitment);

            $previous = $commitment->status;
            $note = self::text($note);
            $commitment->update(['status' => CommitmentStatus::Fulfilled, 'fulfilled_at' => now()]);

            $this->log->log(CommitmentActivity::fulfilled($commitment, $note));

            $this->audit->record(
                'core.commitment.fulfilled',
                'commitment',
                $commitment->id,
                ['status' => ['old' => $previous->value, 'new' => CommitmentStatus::Fulfilled->value]],
                $note === null ? ['client_id' => $client->id] : ['client_id' => $client->id, 'note' => $note],
            );

            $this->clientStatus->afterClose($client, $commitment);
        });

        return $this->load($commitmentId);
    }

    public function cancel(int $commitmentId, string $reason): Commitment
    {
        $this->database->transaction(function () use ($commitmentId, $reason): void {
            [$commitment, $client] = $this->lockForWrite($commitmentId);
            $reason = self::text($reason) ?? throw new InvalidArgumentException('A cancelled commitment needs a reason.');
            self::guardNotFinal($commitment);

            $previous = $commitment->status;
            $commitment->update([
                'status' => CommitmentStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->log->log(CommitmentActivity::cancelled($commitment, $reason));

            $this->audit->record(
                'core.commitment.cancelled',
                'commitment',
                $commitment->id,
                ['status' => ['old' => $previous->value, 'new' => CommitmentStatus::Cancelled->value]],
                ['client_id' => $client->id, 'reason' => $reason],
            );

            $this->clientStatus->afterClose($client, $commitment);
        });

        return $this->load($commitmentId);
    }

    public function find(int $commitmentId): ?Commitment
    {
        $record = $this->visible(CommitmentRecord::query(), $this->reach->scope())
            ->whereKey($commitmentId)
            ->with(CommitmentMapper::RELATIONS)
            ->first();

        return $record === null ? null : $this->mapper->map($record);
    }

    /**
     * Filters combine with AND. Due dates compare as half-open ranges of
     * calendar dates ([dueFrom, the day after dueTo)), which reads the same
     * whether the engine keeps the time the date cast writes (SQLite:
     * "2026-10-22 00:00:00") or only the date (MySQL).
     */
    public function paginate(CommitmentQuery $query, int $perPage = 25): LengthAwarePaginator
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("The perPage must be at least 1, {$perPage} given.");
        }

        $scope = PortfolioScope::forUser($this->auth->guard()->user(), $this->currentCompany, $query->companies);
        $commitments = $this->visible(CommitmentRecord::query(), $scope)
            ->whereIn('commitments.company', array_map(fn (CompanyCode $code): string => $code->value, $scope->companies));

        if ($query->clientId !== null) {
            $commitments->where('commitments.client_id', $query->clientId);
        }

        if ($query->advisorCode !== null) {
            $commitments->where('commitments.advisor_code', $query->advisorCode);
        }

        if ($query->statuses !== []) {
            $commitments->whereIn('commitments.status', array_map(fn (CommitmentStatus $status): string => $status->value, $query->statuses));
        }

        if ($query->dueFrom !== null) {
            $commitments->where('commitments.due_date', '>=', $query->dueFrom->toDateString());
        }

        if ($query->dueTo !== null) {
            $commitments->where('commitments.due_date', '<', CarbonImmutable::parse($query->dueTo->toDateString())->addDay()->toDateString());
        }

        $page = $commitments
            ->orderBy("commitments.{$query->sort}", $query->direction === 'desc' ? 'desc' : 'asc')
            ->orderBy('commitments.id')
            ->with(CommitmentMapper::RELATIONS)
            ->paginate($perPage);

        return new Paginator(
            array_map($this->mapper->map(...), $page->items()),
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            $page->getOptions(),
        );
    }

    /**
     * The commitment and its client, locked, when the client is within the
     * user's reach and not deleted, and the write rule lets the user write
     * its commitments.
     *
     * @return array{CommitmentRecord, ClientRecord}
     *
     * @throws RecordNotFound
     * @throws AuthorizationException
     */
    private function lockForWrite(int $commitmentId): array
    {
        $commitment = CommitmentRecord::query()->whereKey($commitmentId)->lockForUpdate()->first();
        $client = $commitment === null
            ? null
            : $this->reach->scope()->clients(ClientRecord::query())->whereKey($commitment->client_id)->lockForUpdate()->first();

        if ($commitment === null || $client === null) {
            throw RecordNotFound::for('commitment', $commitmentId);
        }

        $this->rule->authorize($this->auth->guard()->user(), $client);

        return [$commitment, $client];
    }

    /**
     * Limits $commitments to the clients of $scope that are not deleted.
     *
     * @param  Builder<CommitmentRecord>  $commitments
     * @return Builder<CommitmentRecord>
     */
    private function visible(Builder $commitments, PortfolioScope $scope): Builder
    {
        return $commitments->whereIn('commitments.client_id', $scope->clients(ClientRecord::query())->select('clients.id'));
    }

    /**
     * The commitment, read again with its client and author.
     */
    private function load(int $commitmentId): Commitment
    {
        return $this->mapper->map(CommitmentRecord::query()->with(CommitmentMapper::RELATIONS)->findOrFail($commitmentId));
    }

    /**
     * The amount as the exact decimal string stored, in whole cents.
     *
     * @throws InvalidArgumentException When it is not a number greater than zero (after rounding to cents)
     *                                  or does not fit the column.
     */
    private static function amount(float $amount): string
    {
        $cents = is_finite($amount) ? round($amount, 2) : 0.0;

        if ($cents <= 0 || $cents >= self::AMOUNT_LIMIT) {
            throw new InvalidArgumentException("A commitment amount must be greater than zero, {$amount} given.");
        }

        return number_format($cents, 2, '.', '');
    }

    /**
     * Free text without surrounding spaces; null when blank.
     */
    private static function text(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * The stored value of an editable field, in the form update() compares.
     */
    private static function stored(CommitmentRecord $commitment, string $field): ?string
    {
        return match ($field) {
            'amount' => $commitment->amount,
            'due_date' => $commitment->due_date->toDateString(),
            default => $commitment->notes,
        };
    }

    /**
     * An editable field as the audit log keeps it: amounts in pesos.
     */
    private static function audited(string $field, ?string $value): float|string|null
    {
        return $field === 'amount' && $value !== null ? (float) $value : $value;
    }

    /**
     * @throws DomainException When the commitment is Fulfilled or Cancelled.
     */
    private static function guardNotFinal(CommitmentRecord $commitment): void
    {
        if (in_array($commitment->status, [CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled], true)) {
            throw new DomainException("Commitment {$commitment->id} is already {$commitment->status->value}.");
        }
    }
}
