<?php

namespace App\Modules\Core\Application\Portfolio;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Portfolio\ClientReach;
use Illuminate\Database\ConnectionInterface;

/**
 * Soft-deletes and restores clients for the signed-in user: the routes check
 * core.clients.delete; this checks that the client is within the user's
 * reach (ClientReach), ignoring the soft delete on restore. A removed client
 * keeps its snapshots and log, disappears from every list and contract read,
 * and is never restored by an import.
 */
final class ClientDeletion
{
    public function __construct(
        private readonly ClientReach $reach,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * Soft-deletes $client and audits core.client.deleted.
     *
     * @throws RecordNotFound When the client is out of reach.
     */
    public function delete(ClientRecord $client): void
    {
        $this->guardReach($client, withTrashed: false);

        $this->database->transaction(function () use ($client): void {
            $client->delete();

            $this->audit->record('core.client.deleted', 'client', $client->id, $this->identity($client));
        });
    }

    /**
     * Restores $client and audits core.client.restored; false when it was
     * not deleted, without writing anything.
     *
     * @throws RecordNotFound When the client is out of reach.
     */
    public function restore(ClientRecord $client): bool
    {
        $this->guardReach($client, withTrashed: true);

        if (! $client->trashed()) {
            return false;
        }

        $this->database->transaction(function () use ($client): void {
            $client->restore();

            $this->audit->record('core.client.restored', 'client', $client->id, $this->identity($client));
        });

        return true;
    }

    /**
     * @throws RecordNotFound
     */
    private function guardReach(ClientRecord $client, bool $withTrashed): void
    {
        if ($this->reach->client($client->id, $withTrashed) === null) {
            throw RecordNotFound::for('client', $client->id);
        }
    }

    /**
     * @return array{code: string, name: string, company: string}
     */
    private function identity(ClientRecord $client): array
    {
        return ['code' => $client->code, 'name' => $client->name, 'company' => $client->company->value];
    }
}
