<?php

namespace App\Modules\Core\Application\Commitments;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Domain\Commitments\CommitmentRules;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * The daily task of the commitment lifecycle: every Pending commitment whose
 * due date is before today (CommitmentRules::isBroken(), Bogotá calendar)
 * becomes Broken, and its client moves to Defaulted as
 * CommitmentRules::afterBreak() says.
 *
 * Runs as the system (the console has no signed-in user), so its log and
 * audit entries have no author. Each commitment goes in its own
 * transaction, with the commitment and its client locked and the rule
 * checked again: a failure is reported and rolls back only that commitment,
 * and running the task twice changes nothing more. Commitments of
 * soft-deleted clients wait: they break on the first run after a restore.
 */
final readonly class BreakOverdueCommitments
{
    /**
     * The calendar the due dates belong to.
     */
    public const string TIMEZONE = 'America/Bogota';

    /**
     * Candidate commitments read per query.
     */
    public const int CHUNK = 100;

    public function __construct(
        private CommitmentClientStatus $clientStatus,
        private GestionLogger $log,
        private AuditLogger $audit,
        private ConnectionInterface $database,
    ) {}

    /**
     * Breaks the overdue commitments as of $today (now in Bogotá when null).
     *
     * @return array{broken: int, failed: int} How many were broken, and how many failed and were reported.
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now(self::TIMEZONE);
        $result = ['broken' => 0, 'failed' => 0];

        // A stored date (with or without the time the date cast writes)
        // sorts before today's "Y-m-d" exactly when its day is earlier.
        CommitmentRecord::query()
            ->where('status', CommitmentStatus::Pending)
            ->where('due_date', '<', $today->toDateString())
            ->whereIn('client_id', ClientRecord::query()->select('id'))
            ->select(['id'])
            ->chunkById(self::CHUNK, function (Collection $candidates) use ($today, &$result): void {
                foreach ($candidates as $candidate) {
                    try {
                        $result['broken'] += $this->break($candidate->id, $today) ? 1 : 0;
                    } catch (Throwable $exception) {
                        report($exception);
                        $result['failed']++;
                    }
                }
            });

        return $result;
    }

    /**
     * Breaks one commitment, unless it changed or its client was deleted
     * since it was read.
     */
    private function break(int $commitmentId, CarbonImmutable $today): bool
    {
        return $this->database->transaction(function () use ($commitmentId, $today): bool {
            $commitment = CommitmentRecord::query()->whereKey($commitmentId)->lockForUpdate()->first();

            if ($commitment === null || ! CommitmentRules::isBroken($commitment->status, $commitment->due_date, $today)) {
                return false;
            }

            $client = ClientRecord::query()->whereKey($commitment->client_id)->lockForUpdate()->first();

            if ($client === null) {
                return false;
            }

            $commitment->update(['status' => CommitmentStatus::Broken, 'broken_at' => now()]);

            $this->log->log(CommitmentActivity::broken($commitment));

            $this->audit->record(
                'core.commitment.broken',
                'commitment',
                $commitment->id,
                ['status' => ['old' => CommitmentStatus::Pending->value, 'new' => CommitmentStatus::Broken->value]],
                ['client_id' => $client->id, 'due_date' => $commitment->due_date->toDateString()],
            );

            $this->clientStatus->afterBreak($client, $commitment);

            return true;
        });
    }
}
