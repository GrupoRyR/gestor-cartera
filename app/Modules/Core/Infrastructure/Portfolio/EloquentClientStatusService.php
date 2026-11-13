<?php

namespace App\Modules\Core\Infrastructure\Portfolio;

use App\Modules\Core\Application\Activity\StatusChangeSummary;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\ClientStatusService;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\ConnectionInterface;

/**
 * ClientStatusService over the "clients" table, for the signed-in user.
 *
 * Checks the permission first, so a user without it gets the same refusal
 * for every id; then finds the client within the user's reach (ClientReach)
 * and locks its row, so two simultaneous changes are applied one after the
 * other. The entry in the client's log goes through GestionLogger, written
 * by the user: it moves the client's last activity forward.
 */
final class EloquentClientStatusService implements ClientStatusService
{
    public const string PERMISSION = 'core.clients.change_status';

    public function __construct(
        private readonly Gate $gate,
        private readonly ClientReach $reach,
        private readonly GestionLogger $log,
        private readonly AuditLogger $audit,
        private readonly PortfolioMapper $mapper,
        private readonly ConnectionInterface $database,
    ) {}

    public function change(int $clientId, ClientStatus $status, ?string $note = null): ClientSummary
    {
        $this->gate->authorize(self::PERMISSION);

        $note = $note === null || trim($note) === '' ? null : trim($note);

        $client = $this->database->transaction(function () use ($clientId, $status, $note): ClientRecord {
            $client = $this->reach->scope()->clients(ClientRecord::query())->whereKey($clientId)->lockForUpdate()->first()
                ?? throw RecordNotFound::for('client', $clientId);
            $previous = $client->status;

            if ($previous === $status) {
                return $client;
            }

            $client->update(['status' => $status, 'status_changed_at' => now()]);

            $this->log->log(new NewActivity(
                clientId: $client->id,
                type: ActivityType::StatusChange,
                summary: StatusChangeSummary::manual($previous, $status),
                details: $note,
                metadata: ['from' => $previous->value, 'to' => $status->value],
            ));

            $this->audit->record(
                'core.client.status_changed',
                'client',
                $client->id,
                ['status' => ['old' => $previous->value, 'new' => $status->value]],
                ['source' => 'manual'],
            );

            // The log moved last_activity_at with a query of its own.
            return $client->refresh();
        });

        return $this->mapper->summaries([$client])[0];
    }
}
