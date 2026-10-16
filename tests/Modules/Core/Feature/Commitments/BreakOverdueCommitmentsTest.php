<?php

use App\Modules\Core\Application\Commitments\BreakOverdueCommitments;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * The daily task (core:compromisos-incumplidos) runs on 15/10/2026 at 00:15
 * in Bogotá. Agro Arango is an SGX client of advisor 12 (Carlos), committed
 * and 30 days past due in the latest snapshot.
 */
beforeEach(function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 00:15:00'));
    $this->people = CommitmentRecords::people();
    $this->carlos = $this->people['carlos'];
    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12, 'status' => ClientStatus::Committed]);
    CarteraRecords::invoice(CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-14'), $this->agro, '1000.00', 30);
    $this->commitment = fn (string $dueDate, CommitmentStatus $status = CommitmentStatus::Pending, ?ClientRecord $client = null) => CommitmentRecords::commitment(
        $client ?? $this->agro,
        $this->carlos,
        $dueDate,
        '2500000.00',
        ['status' => $status],
    );
});

it('breaks a pending commitment due before today and defaults its client, as the system', function () {
    $commitment = ($this->commitment)('2026-10-14');

    $this->artisan('core:compromisos-incumplidos')
        ->expectsOutput('Marcamos 1 compromiso como incumplido.')
        ->assertSuccessful();

    $stored = CommitmentRecords::stored($commitment);
    [$entry] = CommitmentRecords::logOf($this->agro, ActivityType::Commitment);
    [$statusEntry] = CommitmentRecords::logOf($this->agro, ActivityType::StatusChange);
    [$audit] = TeamUsers::audits('core.commitment.broken');
    [$statusAudit] = TeamUsers::audits('core.client.status_changed');
    $this->agro->refresh();

    expect($stored->status)->toBe(CommitmentStatus::Broken)
        ->and($stored->broken_at?->toDateTimeString())->toBe('2026-10-15 00:15:00')
        ->and($entry->summary)->toBe('Compromiso incumplido: vencía el 14/10/2026')
        ->and($entry->user_id)->toBeNull()
        ->and($entry->metadata)->toEqual(['commitment_id' => $commitment->id, 'amount' => 2_500_000, 'due_date' => '2026-10-14'])
        ->and($audit->subject_type)->toBe('commitment')
        ->and($audit->subject_id)->toBe((string) $commitment->id)
        ->and($audit->user_id)->toBeNull()
        ->and($audit->actor)->toBeNull()
        ->and($audit->changes)->toEqual(['status' => ['old' => 'pendiente', 'new' => 'incumplido']])
        ->and($audit->context)->toEqual(['client_id' => $this->agro->id, 'due_date' => '2026-10-14'])
        ->and($this->agro->status)->toBe(ClientStatus::Defaulted)
        ->and($this->agro->status_changed_at?->toDateTimeString())->toBe('2026-10-15 00:15:00')
        ->and($statusEntry->summary)->toBe('Por el compromiso de pago: de «Compromiso» a «Incumplido»')
        ->and($statusEntry->user_id)->toBeNull()
        ->and($statusEntry->metadata)->toEqual(['from' => 'compromiso', 'to' => 'incumplido', 'commitment_id' => $commitment->id])
        ->and($statusAudit->user_id)->toBeNull()
        ->and($statusAudit->changes)->toEqual(['status' => ['old' => 'compromiso', 'new' => 'incumplido']])
        ->and($statusAudit->context)->toEqual(['source' => 'commitment', 'commitment_id' => $commitment->id])
        // System entries never move the client's last activity.
        ->and($this->agro->last_activity_at)->toBeNull();
});

it('leaves pending a commitment due today or later', function () {
    $today = ($this->commitment)('2026-10-15');
    $tomorrow = ($this->commitment)('2026-10-16');

    $this->artisan('core:compromisos-incumplidos')
        ->expectsOutput('No hay compromisos vencidos para marcar como incumplidos.')
        ->assertSuccessful();

    expect(CommitmentRecords::statuses($today, $tomorrow))->toBe([CommitmentStatus::Pending, CommitmentStatus::Pending])
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Committed)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(AuditLogRecord::query()->count())->toBe(0);
});

it('counts the days of the Bogotá calendar', function () {
    // 04:30 UTC on the 15th is still 23:30 of the 14th in Bogotá.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 04:30:00', 'UTC'));
    $yesterday = ($this->commitment)('2026-10-13');
    $today = ($this->commitment)('2026-10-14');

    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();

    expect(CommitmentRecords::statuses($yesterday, $today))->toBe([CommitmentStatus::Broken, CommitmentStatus::Pending]);
});

it('never touches fulfilled, cancelled or already broken commitments', function () {
    $commitments = [
        ($this->commitment)('2026-09-30', CommitmentStatus::Fulfilled),
        ($this->commitment)('2026-09-30', CommitmentStatus::Cancelled),
        ($this->commitment)('2026-09-30', CommitmentStatus::Broken),
    ];

    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();

    expect(CommitmentRecords::statuses(...$commitments))->toBe([CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled, CommitmentStatus::Broken])
        ->and(CommitmentRecords::stored($commitments[2])->broken_at)->toBeNull()
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(AuditLogRecord::query()->count())->toBe(0);
});

it('keeps the client committed while another commitment is still pending', function () {
    $broken = ($this->commitment)('2026-10-14');
    ($this->commitment)('2026-10-30');

    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();

    expect(CommitmentRecords::stored($broken)->status)->toBe(CommitmentStatus::Broken)
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Committed)
        ->and(CommitmentRecords::logOf($this->agro, ActivityType::StatusChange))->toBe([])
        ->and(TeamUsers::audits('core.client.status_changed'))->toBe([]);
});

it('keeps a client in legal or payment received', function (ClientStatus $status) {
    $this->agro->update(['status' => $status]);
    $broken = ($this->commitment)('2026-10-14');

    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();

    expect(CommitmentRecords::stored($broken)->status)->toBe(CommitmentStatus::Broken)
        ->and($this->agro->refresh()->status)->toBe($status)
        ->and(TeamUsers::audits('core.client.status_changed'))->toBe([]);
})->with([ClientStatus::Legal, ClientStatus::PaymentReceived]);

it('breaks every overdue commitment of a client and defaults it once', function () {
    $first = ($this->commitment)('2026-10-10');
    $second = ($this->commitment)('2026-10-12');

    $this->artisan('core:compromisos-incumplidos')
        ->expectsOutput('Marcamos 2 compromisos como incumplidos.')
        ->assertSuccessful();

    expect(CommitmentRecords::statuses($first, $second))->toBe([CommitmentStatus::Broken, CommitmentStatus::Broken])
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Defaulted)
        ->and(TeamUsers::audits('core.commitment.broken'))->toHaveCount(2)
        ->and(TeamUsers::audits('core.client.status_changed'))->toHaveCount(1);
});

it('changes nothing when it runs again', function () {
    ($this->commitment)('2026-10-14');
    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();
    $entries = ActivityRecord::query()->count();
    $audits = AuditLogRecord::query()->count();

    $this->artisan('core:compromisos-incumplidos')
        ->expectsOutput('No hay compromisos vencidos para marcar como incumplidos.')
        ->assertSuccessful();

    expect(ActivityRecord::query()->count())->toBe($entries)
        ->and(AuditLogRecord::query()->count())->toBe($audits)
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Defaulted);
});

it('skips the commitments of deleted clients', function () {
    $commitment = ($this->commitment)('2026-10-14');
    $this->agro->delete();

    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();

    expect(CommitmentRecords::stored($commitment)->status)->toBe(CommitmentStatus::Pending);
});

it('breaks more commitments than fit in one chunk', function () {
    foreach (range(1, BreakOverdueCommitments::CHUNK + 1) as $day) {
        ($this->commitment)('2026-10-14');
    }

    $this->artisan('core:compromisos-incumplidos')
        ->expectsOutput('Marcamos '.(BreakOverdueCommitments::CHUNK + 1).' compromisos como incumplidos.')
        ->assertSuccessful();

    expect(CommitmentRecord::query()->where('status', CommitmentStatus::Pending)->count())->toBe(0);
});

it('keeps going when one commitment fails, and reports it', function () {
    Exceptions::fake();
    $bodega = CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 12, 'status' => ClientStatus::Committed]);
    $failing = ($this->commitment)('2026-10-13', client: $bodega);
    $broken = ($this->commitment)('2026-10-14');
    $real = app(GestionLogger::class);
    $this->app->instance(GestionLogger::class, new class($real, $bodega->id) implements GestionLogger
    {
        public function __construct(private GestionLogger $real, private int $failingClientId) {}

        public function log(NewActivity $activity): Activity
        {
            return $activity->clientId === $this->failingClientId
                ? throw new RuntimeException('The log is not available.')
                : $this->real->log($activity);
        }

        public function forClient(int $clientId, int $limit = 50): array
        {
            return $this->real->forClient($clientId, $limit);
        }

        public function recent(int $limit = 20): array
        {
            return $this->real->recent($limit);
        }
    });

    $this->artisan('core:compromisos-incumplidos')
        ->expectsOutput('Marcamos 1 compromiso como incumplido.')
        ->expectsOutput('No pudimos marcar 1 compromiso; el error quedó en el log.')
        ->assertExitCode(Command::FAILURE);

    Exceptions::assertReported(RuntimeException::class);

    expect(CommitmentRecords::statuses($failing, $broken))->toBe([CommitmentStatus::Pending, CommitmentStatus::Broken])
        ->and($bodega->refresh()->status)->toBe(ClientStatus::Committed)
        ->and(TeamUsers::audits('core.commitment.broken'))->toHaveCount(1);
});

it('lets a late payment fulfil a broken commitment and hand the client back to its automatic status', function () {
    $commitment = ($this->commitment)('2026-10-14');
    $this->artisan('core:compromisos-incumplidos')->assertSuccessful();
    $this->travelTo(CarteraRecords::at('2026-10-16 10:00:00'));
    $this->actingAs($this->carlos);

    $fulfilled = CommitmentRecords::service()->markFulfilled($commitment->id, 'Pagó tarde.');

    expect($fulfilled->status)->toBe(CommitmentStatus::Fulfilled)
        ->and($this->agro->refresh()->status)->toBe(ClientStatus::Overdue)
        ->and(TeamUsers::audits('core.client.status_changed')[1]->changes)
        ->toEqual(['status' => ['old' => 'incumplido', 'new' => 'vencida']]);
});

it('runs every day at 00:15 in Bogotá without overlapping', function () {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, 'core:compromisos-incumplidos'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('15 0 * * *')
        ->and($events[0]->timezone)->toBe('America/Bogota')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->expiresAt)->toBe(10);
});
