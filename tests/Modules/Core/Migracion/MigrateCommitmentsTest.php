<?php

use App\Models\User;
use App\Modules\Core\Application\Commitments\CommitmentActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;
use Tests\Modules\Core\Migracion\MigrationFolder;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * core:migrar-compromisos on 12/03/2025 at 10:00 in Bogotá. The app has
 * Laura (Fase 1 user 1) and Andrés (Fase 1 user 2); Paola (3) and Camilo
 * are not in it. Agro (C100, advisor 12) and Ferretería (C200) are SGX
 * clients; Distribuciones (D300, advisor 30) is of SDC.
 */
const COMPROMISOS_ACTIONS = <<<'CSV'
id,client_id,empresa,user_id,tipo,resultado,detalle,compromiso_fecha,compromiso_valor,descuento,adjunto,adjunto_nombre,created_at
1,1,SGX,1,llamada,Promete pagar,Habló con tesorería.,2025-03-15,1500000.00,NULL,,,2025-03-01 09:30:00
2,2,SGX,2,compromiso,Compromiso de pago,Paga el 10,2025-03-10,800000.00,50000.00,,,2025-03-02 10:00:00
3,1,SGX,1,nota,,Sin compromiso,NULL,NULL,NULL,,,2025-03-03 10:00:00
4,1,SGX,1,compromiso,Compromiso,Sin fecha,NULL,500000.00,NULL,,,2025-03-04 10:00:00
5,1,SGX,1,compromiso,,Sin valor,2025-03-20,NULL,NULL,,,2025-03-05 10:00:00
6,1,SGX,1,compromiso,,Valor cero,2025-03-20,0.00,NULL,,,2025-03-05 10:00:00
7,2,SGX,3,compromiso,,De Paola,2025-03-20,100000.00,NULL,,,2025-03-06 10:00:00
8,3,SDC,2,compromiso,,Vence hoy,2025-03-12,250000.00,NULL,,,2025-03-07 10:00:00

CSV;

const COMPROMISOS_GESTIONES = <<<'CSV'
id,codigo_sn,nombre_sn,documento,slp_code,tipo,nota,fecha_compromiso,valor_compromiso,estado,creado_por,creado_en,actualizado_en
201,C100,Agro,,84,compromiso,Paga el viernes,2025-03-14,500000,abierto,andres.ospina@segurex.com,2025-03-01 15:00:00+00,2025-03-01 15:00:00+00
202,C100,Agro,,84,compromiso,Pagó completo,2025-03-05,300000,cumplido,andres.ospina@segurex.com,2025-02-20 15:00:00+00,2025-03-06 20:00:00+00
203,C200,Ferretería,,85,compromiso,No pagó,2025-03-08,200000,abierto,andres.ospina@segurex.com,2025-02-21 15:00:00+00,2025-02-21 15:00:00+00
204,C200,Ferretería,,85,compromiso,Ya no va,2025-03-20,100000,cancelado,andres.ospina@segurex.com,2025-02-22 15:00:00+00,2025-03-07 13:00:00+00
205,C100,Agro,,84,nota,Una nota,,,abierto,andres.ospina@segurex.com,2025-02-23 15:00:00+00,2025-02-23 15:00:00+00
206,C100,Agro,,84,compromiso,De Camilo,2025-03-20,100000,abierto,camilo.duque@segurex.com,2025-02-24 15:00:00+00,2025-02-24 15:00:00+00

CSV;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2025-03-12 10:00:00', 'America/Bogota'));

    $this->folder = MigrationFolder::with('fase1_users.csv', 'fase1_clients.csv', 'fase1_client_codes.csv');
    MigrationFolder::put($this->folder, 'fase1_actions.csv', COMPROMISOS_ACTIONS);
    MigrationFolder::put($this->folder, 'supabase_gestiones.csv', COMPROMISOS_GESTIONES);

    $this->laura = compromisosPerson('laura.restrepo@segurex.com');
    $this->andres = compromisosPerson('andres.ospina@segurex.com');

    $this->agro = compromisosClient(CompanyCode::SGX, 'C100', '900100100-1', 12);
    $this->ferreteria = compromisosClient(CompanyCode::SGX, 'C200', '900200200-5', 85);
    $this->distribuciones = compromisosClient(CompanyCode::SDC, 'D300', '800300300', 30);
});

afterEach(function () {
    MigrationFolder::remove($this->folder);
});

function compromisosPerson(string $email): User
{
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC);
    $user->forceFill(['email' => $email])->save();

    return $user;
}

function compromisosClient(CompanyCode $company, string $code, string $taxId, int $advisor): ClientRecord
{
    return ClientRecord::factory()->create([
        'company' => $company, 'code' => $code, 'tax_id' => $taxId, 'advisor_code' => $advisor,
        'status' => ClientStatus::Overdue, 'balance' => '1000000.00', 'overdue' => '1000000.00',
    ]);
}

/**
 * The commitment imported from the legacy row with $key, through the log
 * entry that carries the key.
 */
function compromisosCommitment(string $key): CommitmentRecord
{
    $entry = ActivityRecord::query()->where('metadata->legacy_key', $key)->sole();

    return CommitmentRecord::query()->findOrFail($entry->metadata['commitment_id']);
}

/**
 * @return array<string, mixed>
 */
function compromisosFields(CommitmentRecord $commitment): array
{
    $at = fn (?CarbonImmutable $moment): ?string => $moment?->setTimezone('America/Bogota')->format('Y-m-d H:i:s');

    return [
        'company' => $commitment->company->value,
        'client' => $commitment->client_id,
        'advisor' => $commitment->advisor_code,
        'amount' => $commitment->amount,
        'due' => $commitment->due_date->toDateString(),
        'status' => $commitment->status,
        'notes' => $commitment->notes,
        'by' => $commitment->created_by,
        'created' => $at($commitment->created_at),
        'fulfilled' => $at($commitment->fulfilled_at),
        'broken' => $at($commitment->broken_at),
        'cancelled' => $at($commitment->cancelled_at),
        'reason' => $commitment->cancellation_reason,
    ];
}

function compromisosRun(string $folder, bool $dryRun = false): mixed
{
    return test()->artisan('core:migrar-compromisos', ['--dir' => $folder, '--dry-run' => $dryRun]);
}

it('imports the commitments of both sources and reports what it could not', function () {
    compromisosRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 8, 3, 1, 4],
            ['Supabase', 'supabase_gestiones.csv', 6, 4, 1, 1],
        ])
        ->expectsTable(MigrationFolder::SKIPPED, [
            ['fase1_actions.csv', 'no es un compromiso', 1],
            ['supabase_gestiones.csv', 'no es un compromiso', 1],
        ])
        ->expectsTable(MigrationFolder::REJECTIONS, [
            ['fase1_actions.csv', 5, '4', 'compromiso sin fecha'],
            ['fase1_actions.csv', 6, '5', 'compromiso sin valor'],
            ['fase1_actions.csv', 7, '6', 'compromiso sin valor'],
            ['fase1_actions.csv', 8, '7', 'el autor no existe en la app: paola.zuluaga@segurex.com; corre primero core:migrar-usuarios'],
            ['supabase_gestiones.csv', 7, '206', 'el autor no existe en la app: camilo.duque@segurex.com; corre primero core:migrar-usuarios'],
        ])
        ->assertSuccessful();

    expect(CommitmentRecord::query()->count())->toBe(7);
});

it('gives Fase 1 commitments a status by their due date', function () {
    compromisosRun($this->folder)->assertSuccessful();

    expect(compromisosFields(compromisosCommitment('fase1:compromiso:1')))->toBe([
        'company' => 'SGX', 'client' => $this->agro->id, 'advisor' => 12, 'amount' => '1500000.00', 'due' => '2025-03-15',
        'status' => CommitmentStatus::Pending, 'notes' => "Llamada: Promete pagar\nHabló con tesorería.", 'by' => $this->laura->id,
        'created' => '2025-03-01 09:30:00', 'fulfilled' => null, 'broken' => null, 'cancelled' => null, 'reason' => null,
    ])
        ->and(compromisosFields(compromisosCommitment('fase1:compromiso:2')))->toBe([
            'company' => 'SGX', 'client' => $this->ferreteria->id, 'advisor' => 85, 'amount' => '800000.00', 'due' => '2025-03-10',
            'status' => CommitmentStatus::Cancelled, 'notes' => 'Paga el 10', 'by' => $this->andres->id,
            'created' => '2025-03-02 10:00:00', 'fulfilled' => null, 'broken' => null, 'cancelled' => '2025-03-12 10:00:00',
            'reason' => 'Importado de Cartera Fase 1 sin estado de cierre.',
        ])
        ->and(compromisosFields(compromisosCommitment('fase1:compromiso:8')))->toMatchArray([
            'company' => 'SDC', 'client' => $this->distribuciones->id, 'advisor' => 30, 'due' => '2025-03-12', 'status' => CommitmentStatus::Pending,
        ]);
});

it('maps the Supabase statuses', function () {
    compromisosRun($this->folder)->assertSuccessful();

    expect(compromisosFields(compromisosCommitment('supabase:compromiso:201')))->toBe([
        'company' => 'SGX', 'client' => $this->agro->id, 'advisor' => 84, 'amount' => '500000.00', 'due' => '2025-03-14',
        'status' => CommitmentStatus::Pending, 'notes' => 'Paga el viernes', 'by' => $this->andres->id,
        'created' => '2025-03-01 10:00:00', 'fulfilled' => null, 'broken' => null, 'cancelled' => null, 'reason' => null,
    ])
        ->and(compromisosFields(compromisosCommitment('supabase:compromiso:202')))->toMatchArray([
            'status' => CommitmentStatus::Fulfilled, 'fulfilled' => '2025-03-06 15:00:00', 'broken' => null, 'cancelled' => null,
        ])
        ->and(compromisosFields(compromisosCommitment('supabase:compromiso:203')))->toMatchArray([
            'status' => CommitmentStatus::Broken, 'broken' => '2025-03-12 10:00:00', 'fulfilled' => null, 'cancelled' => null,
        ])
        ->and(compromisosFields(compromisosCommitment('supabase:compromiso:204')))->toMatchArray([
            'status' => CommitmentStatus::Cancelled, 'cancelled' => '2025-03-07 08:00:00', 'reason' => 'Anulado en el portal de asesores.',
        ]);
});

it('writes the log entry the app writes when a commitment is created', function () {
    compromisosRun($this->folder)->assertSuccessful();

    $commitment = compromisosCommitment('fase1:compromiso:2');
    $entry = ActivityRecord::query()->where('metadata->legacy_key', 'fase1:compromiso:2')->sole();
    $expected = CommitmentActivity::created($commitment);

    expect($entry->type)->toBe(ActivityType::Commitment)
        ->and($entry->summary)->toBe($expected->summary)
        ->and($entry->details)->toBe('Paga el 10')
        ->and($entry->user_id)->toBe($this->andres->id)
        ->and($entry->client_id)->toBe($this->ferreteria->id)
        ->and($entry->occurred_at->format('Y-m-d H:i:s'))->toBe('2025-03-02 10:00:00')
        ->and($entry->metadata)->toEqual([...$expected->metadata, 'legacy_key' => 'fase1:compromiso:2', 'discount_amount' => 50000.0]);
});

it('changes no client status and moves the last activity forward', function () {
    compromisosRun($this->folder)->assertSuccessful();

    expect(ClientRecord::query()->pluck('status')->unique()->values()->all())->toBe([ClientStatus::Overdue])
        ->and($this->agro->refresh()->last_activity_at?->format('Y-m-d H:i:s'))->toBe('2025-03-01 10:00:00');
});

it('imports nothing twice', function () {
    compromisosRun($this->folder)->assertSuccessful();

    compromisosRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 8, 0, 4, 4],
            ['Supabase', 'supabase_gestiones.csv', 6, 0, 5, 1],
        ])
        ->assertSuccessful();

    expect(CommitmentRecord::query()->count())->toBe(7);
});

it('saves nothing in a dry run but reports the same counts', function () {
    compromisosRun($this->folder, dryRun: true)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 8, 3, 1, 4],
            ['Supabase', 'supabase_gestiones.csv', 6, 4, 1, 1],
        ])
        ->expectsOutputToContain('Modo de prueba: no se guardó nada.')
        ->assertSuccessful();

    expect(CommitmentRecord::query()->count())->toBe(0)
        ->and(ActivityRecord::query()->count())->toBe(0);
});

it('imports a call with a commitment date as a call and as a commitment', function () {
    $folder = MigrationFolder::with('fase1_users.csv', 'fase1_clients.csv', 'fase1_client_codes.csv', 'fase1_actions.csv');

    try {
        test()->artisan('core:migrar-gestiones', ['--dir' => $folder])->assertSuccessful();
        compromisosRun($folder)->assertSuccessful();
    } finally {
        MigrationFolder::remove($folder);
    }

    $call = ActivityRecord::query()->where('metadata->legacy_key', 'fase1:actions:1')->sole();
    $commitment = compromisosCommitment('fase1:compromiso:1');

    expect($call->type)->toBe(ActivityType::Call)
        ->and($commitment->due_date->toDateString())->toBe('2025-03-10')
        ->and($commitment->notes)->toBe("Llamada: Contestó y promete pagar\nHabló con la tesorera.\nPide la factura de nuevo.")
        // Fase 1 action 9, the plain commitment, is imported too.
        ->and(compromisosCommitment('fase1:compromiso:9')->amount)->toBe('800000.00');
});

it('keeps only the latest Fase 1 promise of a client pending', function () {
    MigrationFolder::put($this->folder, 'fase1_actions.csv', <<<'CSV'
        id,client_id,empresa,user_id,tipo,resultado,detalle,compromiso_fecha,compromiso_valor,descuento,adjunto,adjunto_nombre,created_at
        1,1,SGX,1,compromiso,,Primera promesa,2025-03-20,100000.00,NULL,,,2025-03-01 10:00:00
        2,1,SGX,2,compromiso,,Segunda promesa,2025-03-25,200000.00,NULL,,,2025-03-05 10:00:00
        3,1,SGX,1,compromiso,,Ya vencida,2025-03-01,50000.00,NULL,,,2025-03-08 10:00:00
        4,1,SGX,1,compromiso,,Sin valor,2025-03-30,NULL,NULL,,,2025-03-09 10:00:00

        CSV);
    unlink($this->folder.DIRECTORY_SEPARATOR.'supabase_gestiones.csv');

    compromisosRun($this->folder)
        ->expectsTable(MigrationFolder::TOTALS, [
            ['Fase 1', 'fase1_actions.csv', 4, 3, 0, 1],
        ])
        ->assertSuccessful();

    expect(compromisosFields(compromisosCommitment('fase1:compromiso:1')))->toMatchArray([
        'status' => CommitmentStatus::Cancelled,
        'cancelled' => '2025-03-12 10:00:00',
        'reason' => 'Reemplazado por un compromiso posterior en Cartera Fase 1.',
    ])
        ->and(compromisosFields(compromisosCommitment('fase1:compromiso:2')))->toMatchArray([
            'status' => CommitmentStatus::Pending, 'cancelled' => null, 'reason' => null,
        ])
        ->and(compromisosFields(compromisosCommitment('fase1:compromiso:3')))->toMatchArray([
            'status' => CommitmentStatus::Cancelled, 'reason' => 'Importado de Cartera Fase 1 sin estado de cierre.',
        ]);
});
