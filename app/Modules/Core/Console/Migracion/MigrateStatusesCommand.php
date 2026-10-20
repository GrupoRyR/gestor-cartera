<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Application\Activity\StatusChangeSummary;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;

/**
 * core:migrar-estados: brings the manual statuses of the Cartera Fase 1
 * clients (fase1_clients.csv, estado_manual = 1) to their clients in the
 * app, found as core:migrar-gestiones finds them.
 *
 * A status is applied only where the app would keep it and nobody chose
 * another: the client still owes money (a snapshot would reset it
 * otherwise) and has no manual status of its own. It is applied as
 * ClientStatusService::change() does, with the client row locked, but as
 * the system: status and status_changed_at, a «Cambio de estado» entry in
 * the log (StatusChangeSummary::manual(), metadata from and to) and the
 * audit entry core.client.status_changed. Like that service, it takes no
 * company lock: the snapshot import keeps manual statuses while the client
 * owes money.
 */
final class MigrateStatusesCommand extends MigrationCommand
{
    private const string CLIENTS = 'fase1_clients.csv';

    private const string CODES = 'fase1_client_codes.csv';

    private const string DETAILS = 'Importado de Cartera Fase 1';

    /**
     * @var string
     */
    protected $name = 'core:migrar-estados';

    /**
     * @var string
     */
    protected $description = 'Importa los estados manuales de los clientes de Cartera Fase 1 desde los CSV exportados';

    private LegacyClients $clients;

    private LegacyActivities $activities;

    private AuditLogger $audit;

    protected function files(): array
    {
        return [
            self::CLIENTS => ['id', 'empresa', 'nit', 'estado', 'estado_manual'],
            self::CODES => ['client_id', 'codigo_sn'],
        ];
    }

    protected function checkFiles(array $files): void
    {
        if (! isset($files[self::CLIENTS])) {
            throw new InvalidCsvFile('No encontré '.self::CLIENTS.': no hay estados que importar.');
        }
    }

    protected function import(array $files, ImportReport $report): void
    {
        $this->clients = LegacyClients::load($files[self::CLIENTS], $files[self::CODES] ?? null, $report);
        $this->activities = new LegacyActivities(LegacyKeys::ofActivities());
        $this->audit = $this->laravel->make(AuditLogger::class);

        $this->eachRow($files[self::CLIENTS], $report, fn (CsvRow $row): bool => $this->apply($row));
    }

    /**
     * @throws RowRejected
     * @throws RowSkipped
     */
    private function apply(CsvRow $row): bool
    {
        $value = $row->string('estado');
        $status = ClientStatus::tryFrom(mb_strtolower($value)) ?? throw new RowRejected("estado desconocido: {$value}");

        if (! $row->bool('estado_manual') || ! ClientStatusRules::isManual($status)) {
            throw new RowSkipped('no es estado manual');
        }

        $legacyId = $row->int('id');
        $target = $this->clients->fase1($legacyId);
        $key = LegacyKeys::key(LegacySource::Fase1, 'estado', (string) $legacyId);
        $client = ClientRecord::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
        $previous = $client->status;

        if ((float) $client->balance <= ClientStatusRules::BALANCE_TOLERANCE) {
            throw new RowSkipped('sin saldo pendiente en la app');
        }

        if ($this->activities->has($key) || $previous === $status) {
            throw new RowSkipped('ya aplicado');
        }

        if (ClientStatusRules::isManual($previous)) {
            throw new RowSkipped("ya tiene estado manual en la app: {$previous->label()}");
        }

        $client->update(['status' => $status, 'status_changed_at' => now()]);

        $this->activities->save(new LegacyActivity(
            key: $key,
            client: $target,
            type: ActivityType::StatusChange,
            summary: StatusChangeSummary::manual($previous, $status),
            details: self::DETAILS,
            result: null,
            author: LegacyAuthor::system(),
            occurredAt: now()->toImmutable(),
            metadata: ['from' => $previous->value, 'to' => $status->value],
        ));

        $this->audit->record(
            'core.client.status_changed',
            'client',
            $client->id,
            ['status' => ['old' => $previous->value, 'new' => $status->value]],
            ['source' => 'migracion', 'file' => $row->file, 'line' => $row->line],
        );

        return true;
    }
}
