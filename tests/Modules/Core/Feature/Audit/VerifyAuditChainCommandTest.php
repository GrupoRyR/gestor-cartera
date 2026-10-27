<?php

use App\Modules\Core\Console\VerifyAuditChainCommand;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\MailSender;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeMailSender;

beforeEach(function () {
    Storage::fake('local');
    config(['core.audit.alert_recipients' => ['ti@segurex.com', 'auditoria@segurex.com']]);
    $this->app->instance(MailSender::class, $this->mail = new FakeMailSender);

    // Five chained entries; the third carries something that must never be mailed.
    $audit = app(AuditLogger::class);
    foreach (range(1, 5) as $n) {
        $audit->record('core.user.updated', 'user', $n, ['name' => ['old' => "Persona {$n}", 'new' => "Persona {$n}b"]], ['nota' => "dato-privado-{$n}"]);
    }

    $this->ids = DB::table('audit_logs')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
});

/**
 * @return array{last_id: int|null, last_hash: string|null}|null
 */
function auditCheckpoint(): ?array
{
    $path = VerifyAuditChainCommand::CHECKPOINT;

    return Storage::disk('local')->exists($path) ? json_decode((string) Storage::disk('local')->get($path), true) : null;
}

/**
 * The only alert sent; fails when there is not exactly one.
 */
function auditAlert(FakeMailSender $mail): OutgoingMail
{
    expect($mail->sent())->toHaveCount(1);

    return $mail->sent()[0];
}

/**
 * Runs the verifier, expects it to fail on $id for $reason and checks the alert.
 */
function expectAuditFailure(FakeMailSender $mail, int $id, string $reason): void
{
    Log::spy();

    test()->artisan('core:verificar-auditoria')
        ->expectsOutputToContain("entrada {$id}")
        ->assertFailed();

    Log::shouldHaveReceived('critical')->withArgs(
        fn (string $message, array $context): bool => $context['first_bad_id'] === $id && $context['reason'] === $reason,
    )->once();

    $alert = auditAlert($mail);

    expect($alert->category)->toBe('alerta')
        ->and($alert->to)->toBe(['ti@segurex.com', 'auditoria@segurex.com'])
        ->and($alert->html)->toContain((string) $id)
        ->and($alert->html)->not->toContain('dato-privado')
        ->and($alert->html)->not->toContain('Persona')
        ->and($alert->subject)->not->toContain('dato-privado');
}

it('passes an intact chain and writes the checkpoint', function () {
    $this->artisan('core:verificar-auditoria')
        ->expectsOutputToContain('5 entradas')
        ->assertSuccessful();

    $last = DB::table('audit_logs')->orderByDesc('id')->first();

    expect(auditCheckpoint())->toBe(['last_id' => (int) $last->id, 'last_hash' => $last->chain_hash]);
    $this->mail->assertNothingSent();
});

it('passes an empty audit trail', function () {
    DB::table('audit_logs')->delete();
    DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => null, 'last_hash' => null]);

    $this->artisan('core:verificar-auditoria')->assertSuccessful();

    expect(auditCheckpoint())->toBe(['last_id' => null, 'last_hash' => null]);
});

it('fails when a row was changed through the query builder', function () {
    DB::table('audit_logs')->where('id', $this->ids[2])->update(['context' => json_encode(['nota' => 'otra cosa'])]);

    expectAuditFailure($this->mail, $this->ids[2], 'hash_mismatch');
});

it('fails when a row in the middle was deleted', function () {
    DB::table('audit_logs')->where('id', $this->ids[2])->delete();

    // The row after the gap no longer follows from its predecessor.
    expectAuditFailure($this->mail, $this->ids[3], 'hash_mismatch');
});

it('fails when a forged row was inserted', function () {
    $forged = (int) DB::table('audit_logs')->insertGetId([
        'action' => 'core.user.deleted',
        'subject_type' => 'user',
        'subject_id' => '9',
        'created_at' => now()->format('Y-m-d H:i:s'),
        'chain_hash' => str_repeat('a', 64),
    ]);

    expectAuditFailure($this->mail, $forged, 'hash_mismatch');
});

it('fails when a row has no hash', function () {
    $forged = (int) DB::table('audit_logs')->insertGetId([
        'action' => 'core.user.deleted',
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]);

    expectAuditFailure($this->mail, $forged, 'missing_hash');
});

it('fails when the last row was deleted and the head still points to it', function () {
    DB::table('audit_logs')->where('id', $this->ids[4])->delete();

    expectAuditFailure($this->mail, $this->ids[4], 'head_mismatch');
});

it('fails through the checkpoint when the last row was deleted and the head rewound', function () {
    $this->artisan('core:verificar-auditoria')->assertSuccessful();

    $previous = DB::table('audit_logs')->where('id', $this->ids[3])->first();
    DB::table('audit_logs')->where('id', $this->ids[4])->delete();
    DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => $previous->id, 'last_hash' => $previous->chain_hash]);

    expectAuditFailure($this->mail, $this->ids[4], 'checkpoint_mismatch');
});

it('does not overwrite the checkpoint after a failed run', function () {
    $this->artisan('core:verificar-auditoria')->assertSuccessful();
    $checkpoint = auditCheckpoint();

    app(AuditLogger::class)->record('core.user.created', 'user', 6);
    DB::table('audit_logs')->where('id', $this->ids[0])->update(['actor' => 'otro@segurex.com']);

    $this->artisan('core:verificar-auditoria')->assertFailed();

    expect(auditCheckpoint())->toBe($checkpoint);
});

it('logs and fails without recipients', function () {
    config(['core.audit.alert_recipients' => []]);
    DB::table('audit_logs')->where('id', $this->ids[1])->update(['subject_id' => '99']);
    Log::spy();

    $this->artisan('core:verificar-auditoria')->assertFailed();

    Log::shouldHaveReceived('critical')->once();
    $this->mail->assertNothingSent();
});

it('runs every day at 02:30 in Bogotá without overlapping', function () {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'core:verificar-auditoria'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('30 2 * * *')
        ->and($events[0]->timezone)->toBe('America/Bogota')
        ->and($events[0]->withoutOverlapping)->toBeTrue();
});
