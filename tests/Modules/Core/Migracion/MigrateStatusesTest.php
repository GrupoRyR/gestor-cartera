<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Carbon\CarbonImmutable;
use Tests\Modules\Core\Migracion\MigrationFolder;

/*
 * core:migrar-estados on 12/03/2025 at 10:00 in Bogotá, with one Fase 1
 * client per rule. Every app client is of SGX, owes money and is "Vencida",
 * unless the row says otherwise.
 */
const ESTADOS_CLIENTS = <<<'CSV'
id,empresa,nit,nombre,estado,estado_manual
1,SGX,900100100,Aplica,juridico,1
2,SGX,900200200,Marcado automático,en_gestion,0
3,SGX,900300300,Estado automático,vencida,1
4,SGX,900400400,Estado raro,rarito,1
5,SGX,900500500,Sin saldo,compromiso,1
6,SGX,900600600,Ya igual,incumplido,1
7,SGX,900700700,Manual distinto,en_gestion,1
8,SGX,900999999,Fantasma,juridico,1

CSV;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-03-12 10:00:00', 'America/Bogota'));

    $this->folder = MigrationFolder::with();
    MigrationFolder::put($this->folder, 'fase1_clients.csv', ESTADOS_CLIENTS);

    $this->applies = estadosClient('C1', '900100100-1');
    $this->noBalance = estadosClient('C5', '900500500-1', ['balance' => '0.00', 'overdue' => '0.00']);
    $this->same = estadosClient('C6', '900600600-1', ['status' => ClientStatus::Defaulted]);
    $this->manual = estadosClient('C7', '900700700-1', ['status' => ClientStatus::Legal]);
    $this->others = [estadosClient('C2', '900200200-1'), estadosClient('C3', '900300300-1'), estadosClient('C4', '900400400-1')];
});

afterEach(function () {
    MigrationFolder::remove($this->folder);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function estadosClient(string $code, string $taxId, array $attributes = []): ClientRecord
{
    return ClientRecord::factory()->create([
        'company' => CompanyCode::SGX, 'code' => $code, 'tax_id' => $taxId,
        'status' => ClientStatus::Overdue, 'balance' => '1000000.00', 'overdue' => '1000000.00', ...$attributes,
    ]);
}

function estadosRun(string $folder, bool $dryRun = false): mixed
{
    return test()->artisan('core:migrar-estados', ['--dir' => $folder, '--dry-run' => $dryRun]);
}

it('applies a manual status only where every rule allows it', function () {
    estadosRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_clients.csv', 8, 1, 5, 2],
        ])
        ->expectsTable(MigrationFolder::SKIPPED, [
            ['fase1_clients.csv', 'no es estado manual', 2],
            ['fase1_clients.csv', 'sin saldo pendiente en la app', 1],
            ['fase1_clients.csv', 'ya aplicado', 1],
            ['fase1_clients.csv', 'ya tiene estado manual en la app: Jurídico', 1],
        ])
        ->expectsTable(MigrationFolder::REJECTIONS, [
            ['fase1_clients.csv', 5, '4', 'estado desconocido: rarito'],
            ['fase1_clients.csv', 9, '8', 'cliente no existe en la app (SGX, NIT 900999999)'],
        ])
        ->assertSuccessful();

    expect($this->applies->refresh()->status)->toBe(ClientStatus::Legal)
        ->and($this->applies->status_changed_at?->format('Y-m-d H:i:s'))->toBe('2025-03-12 10:00:00')
        ->and($this->noBalance->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and($this->same->refresh()->status)->toBe(ClientStatus::Defaulted)
        ->and($this->manual->refresh()->status)->toBe(ClientStatus::Legal)
        ->and(collect($this->others)->map(fn (ClientRecord $client) => $client->refresh()->status)->unique()->values()->all())->toBe([ClientStatus::Overdue]);
});

it('logs and audits the change as the app does, as the system', function () {
    estadosRun($this->folder)->assertSuccessful();

    $entry = ActivityRecord::query()->where('client_id', $this->applies->id)->sole();
    $audit = AuditLogRecord::query()->where('action', 'core.client.status_changed')->sole();

    expect($entry->type)->toBe(ActivityType::StatusChange)
        ->and($entry->summary)->toBe('De «Vencida» a «Jurídico»')
        ->and($entry->details)->toBe('Importado de Cartera Fase 1')
        ->and($entry->user_id)->toBeNull()
        ->and($entry->metadata)->toEqual(['legacy_key' => 'fase1:estado:1', 'from' => 'vencida', 'to' => 'juridico'])
        ->and($this->applies->refresh()->last_activity_at)->toBeNull()
        ->and($audit->subject_type)->toBe('client')
        ->and($audit->subject_id)->toBe((string) $this->applies->id)
        ->and($audit->changes)->toEqual(['status' => ['old' => 'vencida', 'new' => 'juridico']])
        ->and($audit->context)->toEqual(['source' => 'migracion', 'file' => 'fase1_clients.csv', 'line' => 2]);
});

it('does not apply a status twice, even after the app changed it back', function () {
    estadosRun($this->folder)->assertSuccessful();
    $this->applies->refresh()->update(['status' => ClientStatus::Overdue]);

    estadosRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_clients.csv', 8, 0, 6, 2],
        ])
        ->assertSuccessful();

    expect($this->applies->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(ActivityRecord::query()->count())->toBe(1);
});

it('saves nothing in a dry run', function () {
    estadosRun($this->folder, dryRun: true)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_clients.csv', 8, 1, 5, 2],
        ])
        ->expectsOutputToContain('Modo de prueba: no se guardó nada.')
        ->assertSuccessful();

    expect($this->applies->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(AuditLogRecord::query()->count())->toBe(0);
});
