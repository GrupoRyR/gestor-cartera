<?php

use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Ingesta\Application\Failures\LoadFailure;
use App\Modules\Ingesta\Application\Failures\RecordLoadFailure;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadAlertRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Ingesta\Support\IngestaLoads;
use Tests\Modules\Ingesta\Support\IngestaUsers;

beforeEach(function () {
    // Wednesday, 30 minutes after the 05:00 slot.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:30:00', 'America/Bogota'));
    config(['ingesta.alert_recipients' => ['ti@segurex.com', 'cartera@segurex.com']]);
    $this->fakes = ContractFakes::install($this->app);
    $this->admin = IngestaUsers::create(Role::Admin);
});

/**
 * Stores a robot failure report of the SGX cartera for the 05:00 slot,
 * without running its alert job.
 */
function reportSlotFailure(bool $final): void
{
    Queue::fake();

    app(RecordLoadFailure::class)->record(new LoadFailure(
        source: FailureSource::Robot,
        reportId: '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b',
        runId: '0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d',
        company: CompanyCode::SGX,
        dataset: Dataset::Receivables,
        slotAt: CarbonImmutable::parse('2026-11-25T05:00:00-05:00'),
        stage: FailureStage::Vpn,
        cause: FailureCause::Vpn,
        transient: true,
        attempt: 3,
        maxAttempts: 3,
        final: $final,
        message: 'No hay ruta a HANA tras 60 s',
        occurredAt: CarbonImmutable::parse('2026-11-25T05:21:10-05:00'),
    ));
}

/**
 * The text of a mail, without tags and with collapsed spaces.
 */
function watchMailText(OutgoingMail $mail): string
{
    return trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($mail->html, ENT_QUOTES | ENT_HTML5))));
}

it('stays quiet when a load of any source arrived after the slot', function (LoadSource $source) {
    IngestaLoads::accepted(['source' => $source, 'received_at' => CarbonImmutable::parse('2026-11-25 05:10:00', 'America/Bogota')]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('Segurex Latam: llegó la carga de las 05:00.')
        ->assertSuccessful();

    $this->fakes->mail->assertNothingSent();
    $this->fakes->avisos->assertNothingSent();
})->with([LoadSource::Robot, LoadSource::Manual]);

it('alerts when the only load arrived before the slot', function () {
    IngestaLoads::accepted(['received_at' => CarbonImmutable::parse('2026-11-25 04:59:59', 'America/Bogota')]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])->assertSuccessful();

    $this->fakes->mail->assertSent(times: 1);
    expect($this->fakes->avisos->sent())->toHaveCount(1);
});

it('stays quiet when the robot reported a final failure for the slot', function () {
    reportSlotFailure(final: true);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('Segurex Latam: el robot reportó la falla de la carga de las 05:00; ya se avisó.')
        ->assertSuccessful();

    $this->fakes->mail->assertNothingSent();
    $this->fakes->avisos->assertNothingSent();
});

it('alerts when the only report of the slot is not final', function () {
    reportSlotFailure(final: false);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])->assertSuccessful();

    $this->fakes->mail->assertSent(times: 1);
});

it('mails the recipients and rings one bell when neither a load nor a report arrived', function () {
    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('Segurex Latam: no llegó la carga de las 05:00; se envió la alerta a ti@segurex.com, cartera@segurex.com.')
        ->assertSuccessful();

    $robot = 'No llegó la carga de las 05:00 ni un reporte del robot: el robot no corrió (¿PC apagado, sin sesión o sin internet?).';
    $this->fakes->mail->assertSent(times: 1);
    $mail = $this->fakes->mail->sent()[0];

    expect($mail->company)->toBe(CompanyCode::SGX)
        ->and($mail->to)->toBe(['ti@segurex.com', 'cartera@segurex.com'])
        ->and($mail->category)->toBe('alerta')
        ->and($mail->subject)->toBe('Alerta: no llegó la carga de cartera de Segurex Latam de las 05:00 del 25/11/2026')
        ->and(watchMailText($mail))->toContain($robot)->not->toContain('datos anteriores')
        ->and($mail->html)->toContain('href="'.e(route('ingesta.index', ['lote' => 'cartera', 'empresa' => 'SGX'])).'"');

    /** @var Aviso $aviso */
    $aviso = $this->fakes->avisos->sent()[0];

    expect($this->fakes->avisos->sent())->toHaveCount(1)
        ->and($aviso->kind)->toBe('ingesta.carga')
        ->and($aviso->key)->toBe('ingesta.carga:SGX:cartera')
        ->and($aviso->company)->toBe('SGX')
        ->and($aviso->title)->toBe('No llegó la carga de cartera de SGX de las 05:00')
        ->and($aviso->body)->toBe($robot)
        ->and($aviso->data)->toBe(['dataset' => 'cartera', 'slot' => '2026-11-25T05:00:00-05:00', 'reason' => 'no_llego'])
        ->and($aviso->recipients)->toBe([$this->admin->id]);

    expect(LoadAlertRecord::query()->orderBy('kind')->pluck('kind')->all())->toBe([LoadAlertKind::Campana, LoadAlertKind::NoLlego])
        ->and(LoadAlertRecord::query()->first()?->slot_at->format('Y-m-d H:i:s e'))->toBe('2026-11-25 05:00:00 America/Bogota');
});

it('blames the Bases SAP reader when it is the expected source', function () {
    config(['ingesta.expected_source' => 'bases_sap']);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])->assertSuccessful();

    expect(watchMailText($this->fakes->mail->sent()[0]))->toContain('No llegó la carga de las 05:00: el lector de Bases SAP no corrió.')
        ->and($this->fakes->avisos->sent()[0]->body)->toBe('No llegó la carga de las 05:00: el lector de Bases SAP no corrió.');
});

it('warns what the Wednesday 16:00 load comes before', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 16:30:00', 'America/Bogota'));

    $this->artisan('ingesta:vigilar-carga', ['hora' => '16:00'])->assertSuccessful();

    $sentence = 'Si no llega a tiempo, el cobro semanal de las 17:00 saldrá con los datos anteriores.';

    expect(watchMailText($this->fakes->mail->sent()[0]))->toContain($sentence)
        ->and($this->fakes->avisos->sent()[0]->body)->toEndWith($sentence);
});

it('checks nothing on a day the slot does not run, or before the slot', function (string $now, string $output) {
    $this->travelTo(CarbonImmutable::parse($now, 'America/Bogota'));

    $this->artisan('ingesta:vigilar-carga', ['hora' => '16:00'])
        ->expectsOutputToContain($output)
        ->assertSuccessful();

    $this->fakes->mail->assertNothingSent();
})->with([
    'thursday' => ['2026-11-26 16:30:00', 'Hoy no hay carga programada a las 16:00'],
    'before the slot' => ['2026-11-25 15:00:00', 'Aún no son las 16:00'],
]);

it('alerts once per slot however many times it runs', function () {
    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])->assertSuccessful();
    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('Segurex Latam: no llegó la carga de las 05:00; la alerta ya se había enviado.')
        ->assertSuccessful();

    $this->fakes->mail->assertSent(times: 1);
    expect($this->fakes->avisos->sent())->toHaveCount(1);
});

it('still mails but rings no second bell when the slot is already in the bell', function () {
    LoadAlertRecord::query()->insert([
        'company' => 'SGX',
        'dataset' => 'cartera',
        'slot_at' => CarbonImmutable::parse('2026-11-25 05:00:00', 'America/Bogota')->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s'),
        'kind' => LoadAlertKind::Campana->value,
        'created_at' => CarbonImmutable::now()->format('Y-m-d H:i:s'),
    ]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])->assertSuccessful();

    $this->fakes->avisos->assertNothingSent();
    $this->fakes->mail->assertSent(times: 1);
});

it('watches only SGX by default, and every configured company', function () {
    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->doesntExpectOutputToContain('Soluciones y Derivados')
        ->assertSuccessful();

    config(['ingesta.watch_companies' => ['SGX', 'SDC']]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('Soluciones y Derivados: no llegó la carga de las 05:00; se envió la alerta')
        ->assertSuccessful();

    expect($this->fakes->mail->sent())->toHaveCount(2)
        ->and($this->fakes->mail->sent()[1]->subject)->toContain('de Soluciones y Derivados de las 05:00')
        ->and($this->fakes->avisos->sent()[1]->key)->toBe('ingesta.carga:SDC:cartera');
});

it('rolls the mail claim back and fails when Graph refuses the mail', function () {
    $this->fakes->mail->failNextWith(new MailNotSent('Graph is down'));

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('Segurex Latam: no llegó la carga de las 05:00 y no se pudo enviar la alerta.')
        ->assertFailed();

    expect(LoadAlertRecord::query()->pluck('kind')->all())->toBe([LoadAlertKind::Campana]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])->assertSuccessful();

    $this->fakes->mail->assertSent(times: 1);
    expect($this->fakes->avisos->sent())->toHaveCount(1);
});

it('only warns without recipients and fails without MailSender, ringing the bell anyway', function () {
    config(['ingesta.alert_recipients' => []]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('no hay destinatarios configurados')
        ->assertSuccessful();

    config(['ingesta.alert_recipients' => ['ti@segurex.com']]);
    $this->app->forgetInstance(MailSender::class);
    unset($this->app[MailSender::class]);

    $this->artisan('ingesta:vigilar-carga', ['hora' => '05:00'])
        ->expectsOutputToContain('MailSender sin implementación')
        ->assertFailed();

    expect($this->fakes->avisos->sent())->toHaveCount(1)
        ->and(LoadAlertRecord::query()->pluck('kind')->all())->toBe([LoadAlertKind::Campana]);
});

it('refuses a time that is not a configured slot', function () {
    $this->artisan('ingesta:vigilar-carga', ['hora' => '07:00'])
        ->expectsOutputToContain('07:00 no es una hora programada de carga')
        ->assertFailed();

    $this->fakes->mail->assertNothingSent();
});

it('runs 30 minutes after each slot in Bogotá without overlapping', function () {
    $this->app->forgetInstance(Schedule::class);

    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'ingesta:vigilar-carga'),
    ));

    expect(array_map(fn (ScheduledEvent $event): string => $event->expression, $events))
        ->toBe(['30 0 * * *', '30 5 * * *', '30 12 * * *', '30 16 * * 3']);

    foreach (['00:00', '05:00', '12:00', '16:00'] as $index => $time) {
        expect((string) $events[$index]->command)->toContain($time)
            ->and($events[$index]->timezone)->toBe('America/Bogota')
            ->and($events[$index]->withoutOverlapping)->toBeTrue();
    }
});
