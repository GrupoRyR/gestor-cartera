<?php

namespace App\Modules\Core\Application\Activity;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Activity\LastActivity;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Portfolio\ClientReach;
use Illuminate\Database\ConnectionInterface;

/**
 * Soft-deletes an entry of a client's log (GestionLogger only appends and
 * reads), for the signed-in user: the route checks core.activities.delete;
 * this checks that the entry's client is within the user's reach.
 */
final class DeleteActivity
{
    public function __construct(
        private readonly ClientReach $reach,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * Soft-deletes $activity, sets the client's last activity back to its
     * latest entry left, and audits core.activity.deleted.
     *
     * @throws RecordNotFound When the entry's client is deleted or out of reach.
     */
    public function handle(ActivityRecord $activity): void
    {
        $client = $this->reach->client($activity->client_id) ?? throw RecordNotFound::for('activity', $activity->id);

        $this->database->transaction(function () use ($activity, $client): void {
            $activity->delete();

            LastActivity::recompute($client->id);

            $this->audit->record(
                'core.activity.deleted',
                'activity',
                $activity->id,
                ['type' => $activity->type->value, 'summary' => $activity->summary, 'client' => $client->name],
                ['client_id' => $client->id],
            );
        });
    }
}
