<?php

namespace App\Modules\Core\Application\Commitments;

use App\Modules\Core\Application\Portfolio\RecomputeClientStatuses;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Domain\Commitments\CommitmentRules;
use App\Modules\Core\Domain\Status\StatusDecision;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;

/**
 * Applies CommitmentRules to the client of a commitment after each step of
 * its life. Callers run it inside their transaction, with the client's row
 * locked and the commitment already saved in its new status.
 *
 * Every change saves status and status_changed_at, leaves an
 * ActivityType::StatusChange entry in the client's log (written by the
 * signed-in user, or by the system in the daily task) and is audited as
 * core.client.status_changed with the context source = commitment.
 */
final readonly class CommitmentClientStatus
{
    public function __construct(
        private GestionLogger $log,
        private AuditLogger $audit,
        private RecomputeClientStatuses $recompute,
    ) {}

    /**
     * A new commitment moves the client to Committed, except in Legal.
     */
    public function afterCreate(ClientRecord $client, CommitmentRecord $commitment): void
    {
        $this->apply($client, CommitmentRules::afterCreate($client->status), $commitment);
    }

    /**
     * A broken commitment moves the client to Defaulted, unless another
     * commitment is still pending or the client is in Legal or
     * PaymentReceived.
     */
    public function afterBreak(ClientRecord $client, CommitmentRecord $commitment): void
    {
        $this->apply($client, CommitmentRules::afterBreak($client->status, $this->hasOtherPending($commitment)), $commitment);
    }

    /**
     * A fulfilled or cancelled commitment hands the client back to its
     * automatic status from the latest snapshot, unless another commitment
     * is still pending or someone chose another status meanwhile.
     */
    public function afterClose(ClientRecord $client, CommitmentRecord $commitment): void
    {
        $decision = CommitmentRules::afterClose(
            $client->status,
            $this->hasOtherPending($commitment),
            $this->hasOther($commitment, CommitmentStatus::Broken),
        );

        $this->apply($client, $decision, $commitment);
    }

    private function apply(ClientRecord $client, StatusDecision $decision, CommitmentRecord $commitment): void
    {
        $status = match (true) {
            $decision->isSet() => $decision->status(),
            $decision->isRecompute() => $this->recompute->automaticStatus($client),
            default => null,
        };
        $previous = $client->status;

        if ($status === null || $status === $previous) {
            return;
        }

        $client->update(['status' => $status, 'status_changed_at' => now()]);

        $this->log->log(CommitmentActivity::statusChange($client->id, $previous, $status, $commitment->id));

        $this->audit->record(
            'core.client.status_changed',
            'client',
            $client->id,
            ['status' => ['old' => $previous->value, 'new' => $status->value]],
            ['source' => 'commitment', 'commitment_id' => $commitment->id],
        );
    }

    /**
     * Whether the client has a Pending commitment other than $commitment.
     */
    private function hasOtherPending(CommitmentRecord $commitment): bool
    {
        return $this->hasOther($commitment, CommitmentStatus::Pending);
    }

    /**
     * Whether the client has a commitment in $status other than $commitment.
     */
    private function hasOther(CommitmentRecord $commitment, CommitmentStatus $status): bool
    {
        return CommitmentRecord::query()
            ->where('client_id', $commitment->client_id)
            ->where('status', $status)
            ->whereKeyNot($commitment->id)
            ->exists();
    }
}
