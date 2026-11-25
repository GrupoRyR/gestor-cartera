<?php

use App\Models\User;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Infrastructure\Notices\EloquentAvisoService;
use App\Modules\Ingesta\Application\Failures\LoadFailure;
use App\Modules\Ingesta\Application\Failures\LoadNotices;
use App\Modules\Ingesta\Application\Failures\RecordLoadFailure;
use App\Modules\Ingesta\Application\Failures\SendLoadFailureAlert;
use App\Modules\Ingesta\Database\Seeders\IngestaPermissionSeeder;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\EloquentIngestaSink;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadAlertRecord;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Ingesta\Support\IngestaRows;
use Tests\Modules\Ingesta\Support\IngestaUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 05:30:00', 'America/Bogota'));
    config(['ingesta.alert_recipients' => ['TI@Segurex.com', 'cartera@segurex.com', 'gerencia@segurex.com']]);
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * Records a final failure report of the SGX cartera through
 * RecordLoadFailure; with the sync queue of the tests, the alert job runs
 * right away.
 */
function recordNoticedFailure(
    string $reportId = '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b',
    string $slot = '2026-11-23T05:00:00-05:00',
): LoadFailureRecord {
    app(RecordLoadFailure::class)->record(new LoadFailure(
        source: FailureSource::Robot,
        reportId: $reportId,
        runId: '0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d',
        company: CompanyCode::SGX,
        dataset: Dataset::Receivables,
        slotAt: CarbonImmutable::parse($slot),
        stage: FailureStage::Vpn,
        cause: FailureCause::Vpn,
        transient: true,
        attempt: 3,
        maxAttempts: 3,
        final: true,
        message: 'No hay ruta a HANA tras 60 s',
        occurredAt: CarbonImmutable::parse('2026-11-23T05:21:10-05:00'),
    ));

    return LoadFailureRecord::query()->where('report_id', $reportId)->sole();
}

/**
 * A stored notice of the bell, as a link resolver receives it.
 *
 * @param  array<string, mixed>  $data
 */
function loadNotice(array $data = ['dataset' => 'cartera'], ?string $company = 'SGX'): Notice
{
    return new Notice(
        id: 1,
        userId: 1,
        company: $company,
        kind: LoadNotices::KIND,
        title: 'Falló la carga de cartera de SGX de las 05:00',
        body: null,
        subjectType: 'load_failure',
        subjectId: 1,
        key: 'ingesta.carga:SGX:cartera',
        data: $data,
        readAt: null,
        resolvedAt: null,
        createdAt: CarbonImmutable::now(),
    );
}

/**
 * The accepted load of a BatchAccepted event.
 */
function acceptedLoad(CompanyCode $company, Dataset $dataset): LoadRecord
{
    return new LoadRecord(
        id: 7,
        dataset: $dataset,
        company: $company,
        cutoffDate: CarbonImmutable::parse('2026-11-23', 'America/Bogota'),
        source: LoadSource::Robot,
        status: LoadStatus::Accepted,
        rowCount: 1,
        total: null,
        overdue: null,
        receivedAt: CarbonImmutable::now(),
    );
}

it('puts a final failure in the bell of the active alert recipients and admins', function () {
    $ti = User::factory()->create(['email' => 'ti@segurex.com']);
    $cartera = IngestaUsers::create(Role::Admin);
    $cartera->update(['email' => 'cartera@segurex.com']);
    $admin = IngestaUsers::create(Role::Admin);
    User::factory()->inactive()->create(['email' => 'gerencia@segurex.com']);
    IngestaUsers::create(Role::Admin)->update(['is_active' => false]);
    IngestaUsers::create(Role::PortfolioAnalyst);
    $failure = recordNoticedFailure();

    $sent = $this->fakes->avisos->sent();

    expect($sent)->toHaveCount(1);
    /** @var Aviso $aviso */
    $aviso = $sent[0];

    expect($aviso->kind)->toBe('ingesta.carga')
        ->and($aviso->key)->toBe('ingesta.carga:SGX:cartera')
        ->and($aviso->company)->toBe('SGX')
        ->and($aviso->title)->toBe('Falló la carga de cartera de SGX de las 05:00')
        ->and($aviso->body)->toBe('La carga de cartera no se pudo sacar de SAP: falló la conexión, ni después de 3 intentos. Revisa en el PC de TI que la VPN Sophos conecte y vuelve a correr la tarea CarteraWeb-ETL.')
        ->and($aviso->subjectType)->toBe('load_failure')
        ->and($aviso->subjectId)->toBe($failure->id)
        ->and($aviso->data)->toBe(['dataset' => 'cartera', 'slot' => '2026-11-23T05:00:00-05:00', 'cause' => 'vpn', 'stage' => 'VPN'])
        ->and($aviso->recipients)->toBe([$ti->id, $cartera->id, $admin->id]);

    expect(LoadAlertRecord::query()->where('kind', LoadAlertKind::Campana)->sole()->load_failure_id)->toBe($failure->id);
    $this->fakes->mail->assertSent(times: 1);
});

it('rings the bell once per slot however many final reports arrive for it', function () {
    IngestaUsers::create(Role::Admin);

    recordNoticedFailure();
    recordNoticedFailure(reportId: '11111111-2222-4333-8444-555555555555');
    recordNoticedFailure(reportId: '22222222-3333-4444-8555-666666666666', slot: '2026-11-23T11:00:00-05:00');

    expect($this->fakes->avisos->sent())->toHaveCount(2)
        ->and($this->fakes->avisos->sent()[1]->title)->toBe('Falló la carga de cartera de SGX de las 11:00');
});

it('rings the bell when Graph refuses the mail, and a retry does not ring it again', function () {
    IngestaUsers::create(Role::Admin);
    Queue::fake();
    $job = new SendLoadFailureAlert(recordNoticedFailure()->id);
    $this->fakes->mail->failNextWith(new MailNotSent('Graph is down'));

    expect(fn () => app()->call([$job, 'handle']))->toThrow(MailNotSent::class);
    expect($this->fakes->avisos->sent())->toHaveCount(1)
        ->and(LoadAlertRecord::query()->pluck('kind')->all())->toBe([LoadAlertKind::Campana]);

    app()->call([$job, 'handle']);

    $this->fakes->mail->assertSent(times: 1);
    expect($this->fakes->avisos->sent())->toHaveCount(1);
});

it('rings the bell of the admins without alert recipients or MailSender', function () {
    config(['ingesta.alert_recipients' => []]);
    $this->app->forgetInstance(MailSender::class);
    unset($this->app[MailSender::class]);
    $admin = IngestaUsers::create(Role::Admin);

    recordNoticedFailure();

    $this->fakes->avisos->assertSent('ingesta.carga', $admin->id);
    $this->fakes->mail->assertNothingSent();
});

it('skips the bell with a log line when no user receives it', function () {
    Log::spy();

    recordNoticedFailure();

    $this->fakes->avisos->assertNothingSent();
    $this->fakes->mail->assertSent(times: 1);
    expect(LoadAlertRecord::query()->where('kind', LoadAlertKind::Campana)->count())->toBe(0);
    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message): bool => str_contains($message, 'nobody to notify in the bell'));
});

it('still mails when AvisoService has no implementation', function () {
    IngestaUsers::create(Role::Admin);
    $this->app->forgetInstance(AvisoService::class);
    unset($this->app[AvisoService::class]);

    recordNoticedFailure();

    $this->fakes->avisos->assertNothingSent();
    $this->fakes->mail->assertSent(times: 1);
});

it('still mails when the bell fails', function () {
    IngestaUsers::create(Role::Admin);
    $this->app->instance(AvisoService::class, new class implements AvisoService
    {
        public function send(Aviso $aviso): void
        {
            throw new RuntimeException('The notices table is gone.');
        }

        public function resolve(string $key): int
        {
            return 0;
        }

        public function linkUsing(string $kind, Closure $resolver): void {}
    });

    recordNoticedFailure();

    $this->fakes->mail->assertSent(times: 1);
    expect(LoadAlertRecord::query()->where('kind', LoadAlertKind::Campana)->count())->toBe(0);
});

it('resolves the notices of a company and dataset when a load of them is accepted', function () {
    Queue::fake();

    BatchAccepted::dispatch(acceptedLoad(CompanyCode::SGX, Dataset::Receivables));
    BatchAccepted::dispatch(acceptedLoad(CompanyCode::SDC, Dataset::Advisors));

    expect($this->fakes->avisos->resolvedKeys())->toBe(['ingesta.carga:SGX:cartera', 'ingesta.carga:SDC:asesores']);
});

it('closes the bell notice when the sink accepts the next load', function () {
    IngestaUsers::create(Role::Admin);
    recordNoticedFailure();
    Queue::fake();

    app(EloquentIngestaSink::class)->receive(IngestaRows::batch('cartera-SGX-1', [IngestaRows::cartera()], Dataset::Receivables));

    $this->fakes->avisos->assertResolved('ingesta.carga:SGX:cartera');
    expect($this->fakes->avisos->resolve('ingesta.carga:SGX:cartera'))->toBe(0);
});

it('ignores accepted loads while AvisoService has no implementation', function () {
    Queue::fake();
    $this->app->forgetInstance(AvisoService::class);
    unset($this->app[AvisoService::class]);

    BatchAccepted::dispatch(acceptedLoad(CompanyCode::SGX, Dataset::Receivables));

    expect($this->fakes->avisos->resolvedKeys())->toBe([]);
});

it('logs and swallows a failure to resolve, so the accepted load is not answered with an error', function () {
    Queue::fake();
    Log::spy();
    $this->app->instance(AvisoService::class, new class implements AvisoService
    {
        public function send(Aviso $aviso): void {}

        public function resolve(string $key): int
        {
            throw new RuntimeException('The notices table is gone.');
        }

        public function linkUsing(string $kind, Closure $resolver): void {}
    });

    BatchAccepted::dispatch(acceptedLoad(CompanyCode::SGX, Dataset::Receivables));

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'could not resolve'))->once();
});

it('builds the key from the company and the dataset', function () {
    expect(LoadNotices::key(CompanyCode::SDC, Dataset::Receivables))->toBe('ingesta.carga:SDC:cartera');
});

it('links the notice to the load history, filtered, for whoever may see it', function () {
    $this->seed(IngestaPermissionSeeder::class);
    $this->actingAs(IngestaUsers::create(Role::PortfolioAnalyst));

    expect((new LoadNotices)->link(loadNotice()))->toBe(route('ingesta.index', ['lote' => 'cartera', 'empresa' => 'SGX']))
        ->and((new LoadNotices)->link(loadNotice([], null)))->toBe(route('ingesta.index'))
        ->and(app(EloquentAvisoService::class)->link(loadNotice()))->toBe(route('ingesta.index', ['lote' => 'cartera', 'empresa' => 'SGX']));
});

it('gives no link to whoever may not see the load history', function () {
    $this->seed(IngestaPermissionSeeder::class);
    $this->actingAs(IngestaUsers::create(Role::SalesAdvisor));

    expect((new LoadNotices)->link(loadNotice()))->toBeNull()
        ->and(app(EloquentAvisoService::class)->link(loadNotice()))->toBeNull();
});
