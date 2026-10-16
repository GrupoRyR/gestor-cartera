<?php

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeMailSender;
use Tests\Modules\Ingesta\Support\IngestaLoads;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00', 'America/Bogota'));
    config(['ingesta.alert_recipients' => ['ti@segurex.com', 'cartera@segurex.com']]);
    $this->app->instance(MailSender::class, $this->mail = new FakeMailSender);
});

/**
 * The only alert sent; fails when there is not exactly one.
 */
function ingestaSentAlert(FakeMailSender $mail): OutgoingMail
{
    expect($mail->sent())->toHaveCount(1);

    return $mail->sent()[0];
}

/**
 * The text of the alert, without tags and with collapsed spaces.
 */
function ingestaAlertText(OutgoingMail $mail): string
{
    return trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($mail->html, ENT_QUOTES | ENT_HTML5))));
}

it('sends nothing when the robot published the cartera of today', function () {
    IngestaLoads::accepted(['received_at' => CarbonImmutable::parse('2026-10-15 06:10:00', 'America/Bogota')]);

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('Segurex Latam: la cartera del robot llegó a tiempo.')
        ->assertSuccessful();

    $this->mail->assertNothingSent();
});

it('mails the recipients when the robot cartera of today is missing', function () {
    IngestaLoads::accepted([
        'cutoff_date' => '2026-10-14',
        'received_at' => CarbonImmutable::parse('2026-10-14 06:05:00', 'America/Bogota'),
    ]);

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('Segurex Latam: falta la cartera del robot; se envió la alerta a ti@segurex.com, cartera@segurex.com.')
        ->assertSuccessful();

    $this->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->category === 'alerta'
        && $mail->company === CompanyCode::SGX
        && $mail->to === ['ti@segurex.com', 'cartera@segurex.com']
        && $mail->subject === 'Alerta: el robot no publicó la cartera de Segurex Latam del 15/10/2026', times: 1);

    $mail = ingestaSentAlert($this->mail);
    $text = ingestaAlertText($mail);

    expect($text)->toContain('A las 07:00 del 15/10/2026 (hora de Bogotá) no había llegado la carga de cartera del robot de SAP para Segurex Latam')
        ->toContain('Corte 14/10/2026, origen robot, recibida el 14/10/2026 06:05.')
        ->toContain('Hoy no llegó ninguna carga de cartera del robot, ni siquiera rechazada')
        ->toContain('Tampoco hay una carga manual con corte de hoy.')
        ->and($mail->html)->toContain('href="'.route('ingesta.index').'"');
});

it('sends nothing before the deadline, even when the cartera is missing', function () {
    config(['ingesta.alert_deadline' => '07:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 06:59:59', 'America/Bogota'));

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('Aún no son las 07:00 (hora de Bogotá), la hora límite: no se revisa la carga ni se envía la alerta.')
        ->assertSuccessful();

    $this->mail->assertNothingSent();
});

it('alerts once the deadline has passed', function (string $now) {
    config(['ingesta.alert_deadline' => '07:00']);
    $this->travelTo(CarbonImmutable::parse($now, 'America/Bogota'));

    $this->artisan('ingesta:verificar-carga')
        ->doesntExpectOutputToContain('Aún no son las')
        ->assertSuccessful();

    expect($this->mail->sent())->toHaveCount(1);
})->with(['at the deadline' => '2026-10-15 07:00:00', 'later that day' => '2026-10-15 15:30:00']);

it('does not count what is not an accepted robot cartera of today received by now', function (Closure $load) {
    $load();

    $this->artisan('ingesta:verificar-carga')->assertSuccessful();

    expect($this->mail->sent())->toHaveCount(1);
})->with([
    'another company' => [fn () => IngestaLoads::accepted(['company' => CompanyCode::SDC])],
    'another dataset' => [fn () => IngestaLoads::accepted(['dataset' => Dataset::Advisors])],
    'received later' => [fn () => IngestaLoads::accepted(['received_at' => CarbonImmutable::parse('2026-10-15 07:00:01', 'America/Bogota')])],
]);

it('still alerts with a manual load of today, and says so', function () {
    IngestaLoads::accepted(['source' => LoadSource::Manual, 'received_at' => CarbonImmutable::parse('2026-10-15 06:30:00', 'America/Bogota')]);

    $this->artisan('ingesta:verificar-carga')->assertSuccessful();

    expect(ingestaAlertText(ingestaSentAlert($this->mail)))
        ->toContain('Corte 15/10/2026, origen carga manual, recibida el 15/10/2026 06:30.')
        ->toContain('Sí hay una carga manual de cartera con corte de hoy');
});

it('tells the error of the robot load rejected today', function () {
    IngestaLoads::rejected(['rows' => ['El lote de cartera llegó sin filas y este tipo de carga no puede estar vacío.']], [
        'received_at' => CarbonImmutable::parse('2026-10-15 06:02:00', 'America/Bogota'),
    ]);
    IngestaLoads::rejected(['rows' => ['Un rechazo de ayer.']], [
        'received_at' => CarbonImmutable::parse('2026-10-14 06:02:00', 'America/Bogota'),
    ]);

    $this->artisan('ingesta:verificar-carga')->assertSuccessful();

    expect(ingestaAlertText(ingestaSentAlert($this->mail)))
        ->toContain('El robot envió la cartera a las 06:02 y se rechazó: El lote de cartera llegó sin filas y este tipo de carga no puede estar vacío.')
        ->not->toContain('Un rechazo de ayer.')
        ->toContain('Aún no hay ninguna carga de cartera aceptada de esta empresa.');
});

it('checks every configured company', function () {
    config(['ingesta.alert_companies' => ['SGX', 'SDC']]);
    IngestaLoads::accepted();

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('Segurex Latam: la cartera del robot llegó a tiempo.')
        ->expectsOutputToContain('Soluciones y Derivados: falta la cartera del robot; se envió la alerta')
        ->assertSuccessful();

    expect(ingestaSentAlert($this->mail)->company)->toBe(CompanyCode::SDC);
});

it('only logs a warning when there are no recipients', function () {
    config(['ingesta.alert_recipients' => []]);
    Log::spy();

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('no hay destinatarios configurados')
        ->assertSuccessful();

    $this->mail->assertNothingSent();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['company' => 'SGX']);
});

it('fails while MailSender has no implementation', function () {
    $this->app->forgetInstance(MailSender::class);
    unset($this->app[MailSender::class]);
    Log::spy();

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('no se pudo enviar la alerta: MailSender sin implementación')
        ->assertFailed();

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => $message === 'no se pudo enviar la alerta: MailSender sin implementación');
});

it('fails when the mail is not sent', function () {
    $this->mail->failNextWith(new MailNotSent('Graph refused the mail.'));
    Log::spy();

    $this->artisan('ingesta:verificar-carga')
        ->expectsOutputToContain('Segurex Latam: falta la cartera del robot y no se pudo enviar la alerta.')
        ->assertFailed();

    $this->mail->assertNothingSent();
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['company'] === 'SGX' && $context['exception'] instanceof MailNotSent);
});

it('runs every day at the deadline in Bogotá without overlapping', function (string $deadline, string $expression) {
    config(['ingesta.alert_deadline' => $deadline]);
    $this->app->forgetInstance(Schedule::class);

    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'ingesta:verificar-carga'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe($expression)
        ->and($events[0]->timezone)->toBe('America/Bogota')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->expiresAt)->toBe(10);
})->with([
    'default' => ['07:00', '0 7 * * *'],
    'configured' => ['06:30', '30 6 * * *'],
]);
