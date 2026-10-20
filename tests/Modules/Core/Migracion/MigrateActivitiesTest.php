<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Carbon\CarbonImmutable;
use Tests\Modules\Core\Migracion\MigrationFolder;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * core:migrar-gestiones with the invented exports in fixtures/. The app has
 * Laura (Fase 1 user 1) and Andrés (Fase 1 user 2); Paola (3) and Camilo
 * (Supabase) are not in it, and Fase 1 user 9 is not in fase1_users.csv.
 *
 * Fase 1 client 1 (Agro) matches by its SAP code C100, client 2 (Ferretería)
 * by NIT with dots and no check digit, client 3 (Distribuciones, SDC) by NIT
 * with a check digit the app does not have. Client 4 matches two NITs,
 * client 5 none, client 6 two codes.
 */
beforeEach(function () {
    $this->folder = MigrationFolder::with(
        'fase1_users.csv', 'fase1_clients.csv', 'fase1_client_codes.csv', 'fase1_actions.csv', 'supabase_gestiones.csv',
    );

    $this->laura = migracionPerson('laura.restrepo@segurex.com', Role::Admin);
    $this->andres = migracionPerson('andres.ospina@segurex.com', Role::PortfolioAnalyst);

    $this->agro = migracionClient(CompanyCode::SGX, 'C100', '900100100-1');
    $this->ferreteria = migracionClient(CompanyCode::SGX, 'C200', '900200200-5', ['last_activity_at' => '2025-04-01 08:00:00']);
    $this->distribuciones = migracionClient(CompanyCode::SDC, 'D300', '800300300', ['last_activity_at' => '2025-03-05 12:00:00']);
    migracionClient(CompanyCode::SGX, 'E400', '900400400-1');
    migracionClient(CompanyCode::SGX, 'E401', '900400400');
    migracionClient(CompanyCode::SGX, 'C600', '700600600-1');
    migracionClient(CompanyCode::SGX, 'C601', '700600601-1');
});

afterEach(function () {
    MigrationFolder::remove($this->folder);
});

function migracionPerson(string $email, Role $role): User
{
    $user = CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC);
    $user->forceFill(['email' => $email])->save();

    return $user;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function migracionClient(CompanyCode $company, string $code, string $taxId, array $attributes = []): ClientRecord
{
    return ClientRecord::factory()->create(['company' => $company, 'code' => $code, 'tax_id' => $taxId, ...$attributes]);
}

function migracionActivity(string $key): ActivityRecord
{
    return ActivityRecord::withTrashed()->where('metadata->legacy_key', $key)->sole();
}

/**
 * The fields of $activity a test compares, with the time in Bogotá.
 *
 * @return array<string, mixed>
 */
function migracionEntry(ActivityRecord $activity): array
{
    return [
        'client' => $activity->client_id,
        'company' => $activity->company->value,
        'type' => $activity->type,
        'summary' => $activity->summary,
        'details' => $activity->details,
        'result' => $activity->result,
        'user' => $activity->user_id,
        'at' => $activity->occurred_at->setTimezone('America/Bogota')->format('Y-m-d H:i:s'),
    ];
}

function migracionRun(string $folder, bool $dryRun = false): mixed
{
    return test()->artisan('core:migrar-gestiones', ['--dir' => $folder, '--dry-run' => $dryRun]);
}

it('imports the log of both sources and reports what it could not', function () {
    migracionRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 18, 10, 1, 7],
            ['Supabase', 'supabase_gestiones.csv', 4, 2, 1, 1],
        ])
        ->expectsTable(MigrationFolder::SKIPPED, [
            ['fase1_actions.csv', 'se importa con core:migrar-compromisos', 1],
            ['supabase_gestiones.csv', 'se importa con core:migrar-compromisos', 1],
        ])
        ->expectsTable(MigrationFolder::REJECTIONS, [
            ['fase1_actions.csv', 12, '10', 'NIT ambiguo: 900400400 coincide con varios clientes de la app (SGX)'],
            ['fase1_actions.csv', 13, '11', 'cliente no existe en la app (SGX, NIT 900999999)'],
            ['fase1_actions.csv', 14, '12', 'el cliente 6 tiene varios códigos SAP en la app: C600, C601'],
            ['fase1_actions.csv', 15, '13', 'la empresa de la gestión (SDC) no coincide con la del cliente (SGX)'],
            ['fase1_actions.csv', 18, '16', 'valor no reconocido en tipo: fax (se espera llamada, correo, whatsapp, nota, documento, compromiso, pago, sistema)'],
            ['fase1_actions.csv', 19, '17', 'fecha inválida en created_at: 31/02/2025'],
            ['fase1_actions.csv', 20, '18', 'el cliente 99 no está en fase1_clients.csv'],
            ['supabase_gestiones.csv', 6, '104', 'cliente no existe en la app (SGX, código C999)'],
        ])
        ->expectsTable(MigrationFolder::WARNINGS, [
            ['fase1_users.csv', 5, '4', 'se ignora la fila: campo vacío: email'],
            ['fase1_actions.csv', 16, '14', 'no encontré al autor (paola.zuluaga@segurex.com) en la app; la gestión queda sin autor'],
            ['fase1_actions.csv', 17, '15', 'no encontré al autor (usuario Fase 1 #9) en la app; la gestión queda sin autor'],
            ['supabase_gestiones.csv', 2, '101', 'no encontré al autor (camilo.duque@segurex.com) en la app; la gestión queda sin autor'],
        ])
        ->assertSuccessful();

    expect(ActivityRecord::query()->count())->toBe(12);
});

it('maps each Fase 1 type as the app writes it', function () {
    migracionRun($this->folder)->assertSuccessful();

    $agro = $this->agro->id;
    $ferreteria = $this->ferreteria->id;
    $distribuciones = $this->distribuciones->id;
    $entry = fn (int $id): array => migracionEntry(migracionActivity("fase1:actions:{$id}"));

    expect($entry(1))->toBe(['client' => $agro, 'company' => 'SGX', 'type' => ActivityType::Call, 'summary' => 'Llamada: Contestó y promete pagar', 'details' => "Habló con la tesorera.\nPide la factura de nuevo.", 'result' => 'Contestó y promete pagar', 'user' => $this->laura->id, 'at' => '2025-03-01 09:30:00'])
        ->and($entry(2))->toBe(['client' => $agro, 'company' => 'SGX', 'type' => ActivityType::EmailSent, 'summary' => 'Envío de estado de cuenta', 'details' => 'Se envió el estado de cuenta a tesorería', 'result' => null, 'user' => $this->andres->id, 'at' => '2025-03-02 10:00:00'])
        ->and($entry(3))->toBe(['client' => $ferreteria, 'company' => 'SGX', 'type' => ActivityType::Note, 'summary' => 'WhatsApp: Mensaje con la relación de facturas', 'details' => 'Mensaje con la relación de facturas', 'result' => null, 'user' => $this->laura->id, 'at' => '2025-03-03 11:00:00'])
        ->and($entry(4))->toBe(['client' => $ferreteria, 'company' => 'SGX', 'type' => ActivityType::Note, 'summary' => 'Cliente en reestructuración', 'details' => null, 'result' => null, 'user' => $this->laura->id, 'at' => '2025-03-04 12:00:00'])
        ->and($entry(5))->toBe(['client' => $distribuciones, 'company' => 'SDC', 'type' => ActivityType::Note, 'summary' => 'Documento: carta-cobro.pdf', 'details' => 'Carta de cobro firmada', 'result' => null, 'user' => $this->andres->id, 'at' => '2025-03-05 08:00:00'])
        ->and($entry(6))->toBe(['client' => $distribuciones, 'company' => 'SDC', 'type' => ActivityType::Note, 'summary' => 'Pago registrado: Pago recibido por 2.000.000', 'details' => 'Facturas: FV-1 (2.000.000) · Banco: Bancolombia', 'result' => null, 'user' => $this->andres->id, 'at' => '2025-03-06 15:45:00'])
        ->and($entry(7))->toBe(['client' => $agro, 'company' => 'SGX', 'type' => ActivityType::StatusChange, 'summary' => 'De «Vencida» a «Jurídico»', 'details' => null, 'result' => null, 'user' => $this->laura->id, 'at' => '2025-03-07 16:00:00'])
        ->and($entry(8))->toBe(['client' => $agro, 'company' => 'SGX', 'type' => ActivityType::System, 'summary' => 'Cartera en cero', 'details' => 'El cliente no presenta saldo en el último corte cargado.', 'result' => null, 'user' => null, 'at' => '2025-03-08 06:00:00'])
        ->and($entry(15))->toBe(['client' => $ferreteria, 'company' => 'SGX', 'type' => ActivityType::Call, 'summary' => 'Llamada: No contestó', 'details' => null, 'result' => 'No contestó', 'user' => null, 'at' => '2025-03-12 09:00:00']);

    expect(migracionActivity('fase1:actions:1')->metadata)->toEqual(['legacy_key' => 'fase1:actions:1', 'commitment_due_date' => '2025-03-10', 'commitment_amount' => 1500000.0])
        ->and(migracionActivity('fase1:actions:5')->metadata)->toEqual(['legacy_key' => 'fase1:actions:5', 'attachment_name' => 'carta-cobro.pdf', 'legacy_result' => 'Documento de cobro'])
        ->and(migracionActivity('fase1:actions:6')->metadata)->toEqual(['legacy_key' => 'fase1:actions:6', 'discount_amount' => 50000.0])
        ->and(migracionActivity('fase1:actions:7')->metadata)->toEqual(['legacy_key' => 'fase1:actions:7', 'from' => 'vencida', 'to' => 'juridico'])
        ->and(migracionActivity('fase1:actions:14')->metadata)->toEqual(['legacy_key' => 'fase1:actions:14', 'legacy_author' => 'paola.zuluaga@segurex.com'])
        ->and(migracionActivity('fase1:actions:15')->metadata)->toEqual(['legacy_key' => 'fase1:actions:15', 'legacy_author' => 'usuario Fase 1 #9'])
        ->and(migracionActivity('fase1:actions:14')->user_id)->toBeNull();
});

it('imports Supabase notes with their time converted to Bogotá', function () {
    migracionRun($this->folder)->assertSuccessful();

    expect(migracionEntry(migracionActivity('supabase:gestiones:101')))->toBe(['client' => $this->agro->id, 'company' => 'SGX', 'type' => ActivityType::Note, 'summary' => 'Llamé y no contestó.', 'details' => "Llamé y no contestó.\nVuelvo a llamar el lunes.", 'result' => null, 'user' => null, 'at' => '2025-03-20 15:30:00'])
        ->and(migracionActivity('supabase:gestiones:101')->metadata)->toEqual(['legacy_key' => 'supabase:gestiones:101', 'legacy_author' => 'camilo.duque@segurex.com', 'document' => 'FV-1001'])
        ->and(migracionEntry(migracionActivity('supabase:gestiones:102')))->toMatchArray(['client' => $this->ferreteria->id, 'summary' => 'Cliente pide paz y salvo', 'details' => null, 'user' => $this->andres->id, 'at' => '2025-03-21 09:00:00'])
        ->and(migracionActivity('supabase:gestiones:102')->metadata)->toEqual(['legacy_key' => 'supabase:gestiones:102'])
        ->and(ActivityRecord::query()->where('metadata->legacy_key', 'supabase:gestiones:103')->exists())->toBeFalse();
});

it('moves the last activity of a client forward only, and only for entries by a person', function () {
    migracionRun($this->folder)->assertSuccessful();

    $last = fn (ClientRecord $client): ?string => $client->refresh()->last_activity_at?->setTimezone('America/Bogota')->format('Y-m-d H:i:s');

    // Agro: Laura's status change (7th) counts, as in the app; the system
    // entry (8th) is later but does not.
    expect($last($this->agro))->toBe('2025-03-07 16:00:00')
        // Ferretería already had a later activity.
        ->and($last($this->ferreteria))->toBe('2025-04-01 08:00:00')
        ->and($last($this->distribuciones))->toBe('2025-03-06 15:45:00');
});

it('imports nothing twice, not even entries removed in the app', function () {
    migracionRun($this->folder)->assertSuccessful();
    migracionActivity('fase1:actions:4')->delete();

    migracionRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 18, 0, 11, 7],
            ['Supabase', 'supabase_gestiones.csv', 4, 0, 3, 1],
        ])
        ->expectsTable(MigrationFolder::SKIPPED, [
            ['fase1_actions.csv', 'ya importado', 10],
            ['fase1_actions.csv', 'se importa con core:migrar-compromisos', 1],
            ['supabase_gestiones.csv', 'ya importado', 2],
            ['supabase_gestiones.csv', 'se importa con core:migrar-compromisos', 1],
        ])
        ->assertSuccessful();

    expect(ActivityRecord::withTrashed()->count())->toBe(12);
});

it('saves nothing in a dry run but reports the same counts', function () {
    migracionRun($this->folder, dryRun: true)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 18, 10, 1, 7],
            ['Supabase', 'supabase_gestiones.csv', 4, 2, 1, 1],
        ])
        ->expectsOutputToContain('Modo de prueba: no se guardó nada.')
        ->assertSuccessful();

    expect(ActivityRecord::withTrashed()->count())->toBe(0)
        ->and($this->agro->refresh()->last_activity_at)->toBeNull()
        ->and($this->distribuciones->refresh()->last_activity_at)->toEqual(CarbonImmutable::parse('2025-03-05 12:00:00'));
});

it('needs the Fase 1 clients to import the Fase 1 actions', function () {
    unlink($this->folder.DIRECTORY_SEPARATOR.'fase1_clients.csv');

    migracionRun($this->folder)
        ->expectsOutputToContain('Para importar fase1_actions.csv hace falta fase1_clients.csv')
        ->assertFailed();

    expect(ActivityRecord::query()->count())->toBe(0);
});

it('fails when there is no log to import', function () {
    unlink($this->folder.DIRECTORY_SEPARATOR.'fase1_actions.csv');
    unlink($this->folder.DIRECTORY_SEPARATOR.'supabase_gestiones.csv');

    migracionRun($this->folder)
        ->expectsOutputToContain('No encontré fase1_actions.csv ni supabase_gestiones.csv')
        ->assertFailed();
});

it('imports Supabase alone, without the Fase 1 files', function () {
    foreach (['fase1_users.csv', 'fase1_clients.csv', 'fase1_client_codes.csv', 'fase1_actions.csv'] as $file) {
        unlink($this->folder.DIRECTORY_SEPARATOR.$file);
    }

    migracionRun($this->folder)
        ->expectsOutputToContain('No encontré fase1_actions.csv')
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Supabase', 'supabase_gestiones.csv', 4, 2, 1, 1],
        ])
        ->assertSuccessful();
});
