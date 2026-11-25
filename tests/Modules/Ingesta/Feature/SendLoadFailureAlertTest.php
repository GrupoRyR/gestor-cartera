<?php

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Ingesta\Application\Failures\LoadFailure;
use App\Modules\Ingesta\Application\Failures\RecordLoadFailure;
use App\Modules\Ingesta\Application\Failures\SendLoadFailureAlert;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadAlertRecord;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ContractFakes;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 05:30:00', 'America/Bogota'));
    config(['ingesta.alert_recipients' => ['ti@segurex.com', 'cartera@segurex.com']]);
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * Records a failure report through RecordLoadFailure (final by default);
 * with the sync queue of the tests, the alert job runs right away.
 */
function recordFinalFailure(
    FailureCause $cause = FailureCause::Vpn,
    FailureStage $stage = FailureStage::Vpn,
    string $reportId = '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b',
    string $slot = '2026-11-23T05:00:00-05:00',
    bool $final = true,
): LoadFailureRecord {
    app(RecordLoadFailure::class)->record(new LoadFailure(
        source: FailureSource::Robot,
        reportId: $reportId,
        runId: '0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d',
        company: CompanyCode::SGX,
        dataset: Dataset::Receivables,
        slotAt: CarbonImmutable::parse($slot),
        stage: $stage,
        cause: $cause,
        transient: true,
        attempt: 3,
        maxAttempts: 3,
        final: $final,
        message: 'No hay ruta a HANA tras 60 s',
        occurredAt: CarbonImmutable::parse('2026-11-23T05:21:10-05:00'),
    ));

    return LoadFailureRecord::query()->where('report_id', $reportId)->sole();
}

/**
 * The text of a mail, without tags and with collapsed spaces.
 */
function failureAlertText(OutgoingMail $mail): string
{
    return trim((string) preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($mail->html, ENT_QUOTES | ENT_HTML5))));
}

it('mails the alert recipients once from the Segurex mailbox when a final report arrives', function () {
    $failure = recordFinalFailure();

    $this->fakes->mail->assertSent(times: 1);
    $mail = $this->fakes->mail->sent()[0];
    $text = failureAlertText($mail);

    expect($mail->company)->toBe(CompanyCode::SGX)
        ->and($mail->to)->toBe(['ti@segurex.com', 'cartera@segurex.com'])
        ->and($mail->category)->toBe('alerta')
        ->and($mail->subject)->toBe('Alerta: falló la carga de cartera de Segurex Latam de las 05:00 del 23/11/2026')
        ->and($text)->toContain('No hay ruta a HANA tras 60 s')
        ->and($text)->toContain('Etapa: conexión a la VPN')
        ->and($text)->toContain('Intento: 3 de 3')
        ->and($text)->toContain('23/11/2026 05:21');

    $alert = LoadAlertRecord::query()->sole();

    expect($alert->kind)->toBe(LoadAlertKind::Falla)
        ->and($alert->company)->toBe(CompanyCode::SGX)
        ->and($alert->dataset)->toBe(Dataset::Receivables)
        ->and($alert->slot_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:00:00 America/Bogota')
        ->and($alert->load_failure_id)->toBe($failure->id);
});

it('explains the failure in plain words for its cause', function (FailureCause $cause, FailureStage $stage, string $explanation) {
    recordFinalFailure($cause, $stage);

    expect(failureAlertText($this->fakes->mail->sent()[0]))->toContain($explanation);
})->with([
    'vpn' => [FailureCause::Vpn, FailureStage::Vpn, 'La carga de cartera no se pudo sacar de SAP: falló la conexión, ni después de 3 intentos. Revisa en el PC de TI que la VPN Sophos conecte y vuelve a correr la tarea CarteraWeb-ETL.'],
    'hana route' => [FailureCause::HanaRoute, FailureStage::Hana, 'La carga de cartera no se pudo sacar de SAP: falló la conexión, ni después de 3 intentos.'],
    'network' => [FailureCause::Network, FailureStage::Laravel, 'La carga salió de SAP pero no llegó a la plataforma. Revisa el internet del PC de TI.'],
    'credentials' => [FailureCause::Credentials, FailureStage::Hana, 'SAP o la VPN rechazaron el usuario o la clave; actualízalos en el PC de TI.'],
    'data' => [FailureCause::Data, FailureStage::Validation, 'SAP devolvió datos con un formato inesperado; no se publicó nada para no dañar la cartera. Avisa a TI.'],
    'other' => [FailureCause::Configuration, FailureStage::Configuration, 'La carga falló en la etapa de lectura de la configuración.'],
]);

it('mails once per slot however many final reports arrive for it', function () {
    recordFinalFailure();
    recordFinalFailure(reportId: '11111111-2222-4333-8444-555555555555');
    recordFinalFailure(reportId: '22222222-3333-4444-8555-666666666666', slot: '2026-11-23T11:00:00-05:00');

    expect($this->fakes->mail->sent())->toHaveCount(2)
        ->and($this->fakes->mail->sent()[1]->subject)->toContain('de las 11:00 del 23/11/2026')
        ->and(LoadAlertRecord::query()->count())->toBe(2);
});

it('sends nothing for a report that is not final', function () {
    recordFinalFailure(final: false);

    $this->fakes->mail->assertNothingSent();
    expect(LoadAlertRecord::query()->count())->toBe(0);
});

it('logs a warning and sends nothing without alert recipients', function () {
    config(['ingesta.alert_recipients' => []]);
    Log::spy();

    recordFinalFailure();

    $this->fakes->mail->assertNothingSent();
    expect(LoadAlertRecord::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'no alert recipients'));
});

it('logs an error and stops while MailSender has no implementation', function () {
    $this->app->forgetInstance(MailSender::class);
    unset($this->app[MailSender::class]);
    Log::spy();

    recordFinalFailure();

    $this->fakes->mail->assertNothingSent();
    expect(LoadAlertRecord::query()->count())->toBe(0);
    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'MailSender has no implementation'));
});

it('fails when Graph refuses the mail and still sends it on a retry', function () {
    Queue::fake();
    $failure = recordFinalFailure();
    $job = new SendLoadFailureAlert($failure->id);
    $this->fakes->mail->failNextWith(new MailNotSent('Graph is down'));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(MailNotSent::class)
        ->and(LoadAlertRecord::query()->count())->toBe(0);

    app()->call([$job, 'handle']);

    $this->fakes->mail->assertSent(times: 1);
    expect(LoadAlertRecord::query()->count())->toBe(1);
});

it('retries a refused mail a few times, waiting between attempts', function () {
    $job = new SendLoadFailureAlert(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([60, 300]);
});
