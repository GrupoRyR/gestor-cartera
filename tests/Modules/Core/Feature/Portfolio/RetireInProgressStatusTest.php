<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailSender;
use Tests\Modules\Core\Support\CarteraRecords;

/*
 * The migration that retires «En gestión» (en_gestion), run on 20/10/2026 at
 * 10:00 in Bogotá. Segurex's latest snapshot was cut on 15/10/2026, with 15
 * grace days and 5 due soon days. The status is written raw: the enum case is
 * about to go.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-20 10:00:00'));
    $this->snapshot = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-15');

    // An entry written before the migration, which must still verify after it.
    app(AuditLogger::class)->record('core.user.updated', 'user', 7, ['name' => ['old' => 'Ana', 'new' => 'Ana María']]);

    $this->overdue = retiredClient($this->snapshot, 'Agro Arango', '1000.00', 20);
    $this->dueSoon = retiredClient($this->snapshot, 'Bodega Bedoya', '500.00', -3);
    $this->current = retiredClient($this->snapshot, 'Cerámicas Castro', '800.00', -30);
    $this->paid = retiredClient($this->snapshot, 'Droguería Díaz', null);
    $this->deleted = retiredClient($this->snapshot, 'Eléctricos Espinosa', '300.00', 40);
    $this->deleted->delete();

    $this->untouched = [
        'legal' => untouchedClient($this->snapshot, 'Ferretería Franco', ClientStatus::Legal),
        'committed' => untouchedClient($this->snapshot, 'Granos Gómez', ClientStatus::Committed),
        'defaulted' => untouchedClient($this->snapshot, 'Hielos Herrera', ClientStatus::Defaulted),
        'overdue' => untouchedClient($this->snapshot, 'Insumos Isaza', ClientStatus::Overdue),
        'current' => untouchedClient($this->snapshot, 'Jabones Jaramillo', ClientStatus::Current),
    ];
});

/**
 * A client of Segurex left in en_gestion, with one item of $balance in the
 * snapshot due $daysOverdue days before the cutoff, or none when null.
 */
function retiredClient(mixed $snapshot, string $name, ?string $balance, int $daysOverdue = 0): ClientRecord
{
    $client = CarteraRecords::client($name, ['company' => CompanyCode::SGX, 'balance' => $balance ?? '0.00']);

    if ($balance !== null) {
        CarteraRecords::invoice($snapshot, $client, $balance, $daysOverdue);
    }

    DB::table('clients')->where('id', $client->id)->update(['status' => 'en_gestion']);

    return $client;
}

/**
 * A client of Segurex with $status and an overdue item, which the migration must leave alone.
 */
function untouchedClient(mixed $snapshot, string $name, ClientStatus $status): ClientRecord
{
    $client = CarteraRecords::client($name, ['company' => CompanyCode::SGX, 'status' => $status, 'balance' => '900.00']);
    CarteraRecords::invoice($snapshot, $client, '900.00', 30);

    return $client;
}

function runEnGestionRetirement(): void
{
    $migration = require base_path('app/Modules/Core/database/migrations/2026_10_01_000015_retire_in_progress_client_status.php');
    $migration->up();
}

function rawStatus(ClientRecord $client): ?string
{
    $status = DB::table('clients')->where('id', $client->id)->value('status');

    return is_string($status) ? $status : null;
}

it('moves every en_gestion client, soft-deleted ones included, to its automatic status', function () {
    runEnGestionRetirement();

    expect(rawStatus($this->overdue))->toBe('vencida')
        ->and(rawStatus($this->dueSoon))->toBe('por_vencer')
        ->and(rawStatus($this->current))->toBe('al_dia')
        ->and(rawStatus($this->paid))->toBe('pago_recibido')
        ->and(rawStatus($this->deleted))->toBe('vencida')
        ->and(DB::table('clients')->where('status', 'en_gestion')->count())->toBe(0)
        ->and(ClientRecord::withTrashed()->find($this->overdue->id)?->status_changed_at?->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('leaves every other status alone', function () {
    runEnGestionRetirement();

    foreach ($this->untouched as $client) {
        $before = $client->status;
        $client->refresh();

        expect($client->status)->toBe($before)
            ->and($client->status_changed_at)->toBeNull()
            ->and(ActivityRecord::query()->where('client_id', $client->id)->count())->toBe(0);
    }
});

it('leaves one system entry in the log of each moved client, without moving its last activity', function () {
    runEnGestionRetirement();

    $entries = ActivityRecord::query()->orderBy('client_id')->get();
    $entry = $entries->firstWhere('client_id', $this->dueSoon->id);

    expect($entries)->toHaveCount(5)
        ->and($entries->pluck('client_id')->all())->toBe([$this->overdue->id, $this->dueSoon->id, $this->current->id, $this->paid->id, $this->deleted->id])
        ->and($entry->type)->toBe(ActivityType::StatusChange)
        ->and($entry->company)->toBe(CompanyCode::SGX)
        ->and($entry->summary)->toBe('De «En gestión» a «Por vencer»')
        ->and($entry->details)->toBeNull()
        ->and($entry->user_id)->toBeNull()
        ->and($entry->occurred_at->toDateTimeString())->toBe('2026-10-20 10:00:00')
        ->and($entry->metadata)->toEqual(['from' => 'en_gestion', 'to' => 'por_vencer', 'source' => 'retire_in_progress'])
        ->and($entries->firstWhere('client_id', $this->paid->id)->summary)->toBe('De «En gestión» a «Pago recibido»')
        ->and(ClientRecord::withTrashed()->whereNotNull('last_activity_at')->count())->toBe(0);
});

it('audits each change as the system', function () {
    runEnGestionRetirement();

    $audits = AuditLogRecord::query()->where('action', 'core.client.status_changed')->orderBy('id')->get();
    $audit = $audits->firstWhere('subject_id', (string) $this->overdue->id);

    expect($audits)->toHaveCount(5)
        ->and($audit->subject_type)->toBe('client')
        ->and($audit->changes)->toEqual(['status' => ['old' => 'en_gestion', 'new' => 'vencida']])
        ->and($audit->context)->toEqual(['source' => 'retire_in_progress'])
        ->and($audit->user_id)->toBeNull()
        ->and($audit->actor)->toBeNull()
        ->and($audits->firstWhere('subject_id', (string) $this->current->id)->changes)->toEqual(['status' => ['old' => 'en_gestion', 'new' => 'al_dia']]);
});

it('changes nothing when it runs again', function () {
    runEnGestionRetirement();
    $statuses = DB::table('clients')->orderBy('id')->pluck('status', 'id')->all();
    $this->travelTo(CarteraRecords::at('2026-10-21 08:00:00'));

    runEnGestionRetirement();

    expect(DB::table('clients')->orderBy('id')->pluck('status', 'id')->all())->toBe($statuses)
        ->and(ActivityRecord::query()->count())->toBe(5)
        ->and(AuditLogRecord::query()->count())->toBe(6)
        ->and(ClientRecord::query()->find($this->overdue->id)?->status_changed_at?->toDateTimeString())->toBe('2026-10-20 10:00:00');
});

it('keeps the audit hash chain whole, the entries written before it included', function () {
    Storage::fake('local');
    config(['core.audit.alert_recipients' => ['ti@segurex.com']]);
    $this->app->instance(MailSender::class, $mail = new FakeMailSender);
    $before = (array) DB::table('audit_logs')->orderBy('id')->first();

    runEnGestionRetirement();

    $this->artisan('core:verificar-auditoria')
        ->expectsOutputToContain('6 entradas')
        ->assertSuccessful();

    expect((array) DB::table('audit_logs')->orderBy('id')->first())->toBe($before)
        ->and(array_map(fn (OutgoingMail $sent): string => $sent->subject, $mail->sent()))->toBe([]);
});
