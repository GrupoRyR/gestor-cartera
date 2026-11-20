<?php

use App\Modules\Cobro\Circularizacion\Console\SendCircularizationCommand;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroPortfolio;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

/*
 * The circularization goes out on the 5th business day of the month at
 * 09:00 (Bogotá), with the closing snapshot of the month before
 * (CarteraRepository::closingSnapshot), never with the latest load. In
 * December 2026 the 5th business day is Monday the 7th.
 */

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-12-07 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);

    // The latest load (December 6) differs from the closing snapshot.
    $this->portfolio = CobroPortfolio::seed($this->fakes);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-12-06', 'America/Bogota');
});

/**
 * The closing snapshot of November for $company: the Tornillo client owes
 * one invoice, FV-0999, which the latest load no longer has.
 */
function cobroClosingSnapshot(CompanyCode $company = CompanyCode::SGX): FakeCarteraRepository
{
    $portfolio = test()->portfolio;
    $snapshot = new FakeCarteraRepository;
    $snapshot->cutoffDate = CarbonImmutable::parse('2026-11-30', 'America/Bogota');

    if ($company === CompanyCode::SGX) {
        $snapshot->addClient($portfolio->tornillo, Make::invoice(clientId: $portfolio->tornillo->summary->id, documentNumber: 'FV-0999', amount: 1_234_000, daysOverdue: 20));
    } else {
        $snapshot->addClient($portfolio->quimicos, Make::invoice(company: CompanyCode::SDC, clientId: $portfolio->quimicos->summary->id, documentNumber: 'FV-0888', amount: 555_000, daysOverdue: 5));
    }

    test()->fakes->cartera->addClosingSnapshot($company, 2026, 11, $snapshot);

    return $snapshot;
}

function cobroCircularizationEvent(): ScheduledEvent
{
    return collect(app(Schedule::class)->events())
        ->sole(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'cobro:circularizacion'));
}

it('is scheduled daily at 09:00, Bogotá time, and due only on the 5th business day', function (string $moment, bool $due) {
    $event = cobroCircularizationEvent();
    $this->travelTo(CarbonImmutable::parse($moment, 'America/Bogota'));

    expect($event->expression)->toBe('0 9 * * *')
        ->and($event->timezone)->toBe('America/Bogota')
        ->and($event->filtersPass($this->app))->toBe($due);
})->with([
    'Mon 7 December 2026' => ['2026-12-07 09:00', true],
    'Fri 4 December 2026' => ['2026-12-04 09:00', false],
    'Tue 8 December 2026, a holiday' => ['2026-12-08 09:00', false],
    'Mon 9 November 2026' => ['2026-11-09 09:00', true],
    'Fri 8 January 2027' => ['2027-01-08 09:00', true],
]);

it('reads the business day from cobro.circularizacion.business_day', function () {
    config(['cobro.circularizacion.business_day' => 3]);
    $event = cobroCircularizationEvent();

    $this->travelTo(CarbonImmutable::parse('2026-12-03 09:00', 'America/Bogota'));
    expect($event->filtersPass($this->app))->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-12-07 09:00', 'America/Bogota'));
    expect($event->filtersPass($this->app))->toBeFalse();
});

it('shows the next 5th business day as the next run', function (string $now, string $next) {
    $definition = app(RunDefinitions::class)->for(RunKind::Circularization);

    expect($definition->nextRunAt(CarbonImmutable::parse($now, 'America/Bogota'))->format('Y-m-d H:i'))->toBe($next);
})->with([
    'before it, in November' => ['2026-11-19 10:00', '2026-12-07 09:00'],
    'that morning' => ['2026-12-07 08:00', '2026-12-07 09:00'],
    'right after it' => ['2026-12-07 09:01', '2027-01-08 09:00'],
]);

it('sends the closing snapshot of the month before, not the latest load', function () {
    cobroClosingSnapshot(CompanyCode::SGX);

    $this->artisan(SendCircularizationCommand::class, ['--empresa' => ['SGX']])->assertSuccessful();

    $run = Run::query()->sole();
    $item = RunItem::query()->sole();
    $mail = $this->fakes->mail->sent()[0];

    expect($run->scheduled_for->equalTo(CarbonImmutable::parse('2026-12-07 09:00', 'America/Bogota')))->toBeTrue()
        ->and($run->cutoff_date?->toDateString())->toBe('2026-11-30')
        ->and($item->client_id)->toBe($this->portfolio->tornillo->summary->id)
        ->and($item->amount)->toBe('1234000.00')
        ->and($mail->subject)->toBe('Circularización de Cartera – Corte al 30 de noviembre de 2026')
        ->and(VisibleText::of($mail->html))->toContain('FV-0999')->not->toContain('FV-1188');
});

it('keeps one run per month: a later manual run is the same run', function () {
    cobroClosingSnapshot(CompanyCode::SGX);
    $this->artisan(SendCircularizationCommand::class, ['--empresa' => ['SGX']])->assertSuccessful();

    $this->travelTo(CarbonImmutable::parse('2026-12-10 15:00', 'America/Bogota'));
    $this->artisan(SendCircularizationCommand::class, ['--empresa' => ['SGX']])
        ->expectsOutputToContain('ya se preparó')
        ->assertSuccessful();

    expect(Run::query()->count())->toBe(1)
        ->and($this->fakes->mail->sent())->toHaveCount(1);
});

it('holds a company back, and says so, while its closing snapshot has not arrived', function () {
    Log::spy();
    cobroClosingSnapshot(CompanyCode::SGX);

    $this->artisan(SendCircularizationCommand::class)
        ->expectsOutputToContain('La circularización de Soluciones y Derivados no se envió: todavía no llega la foto del cierre del 30/11/2026. '
            .'Cuando llegue, ejecuta php artisan cobro:circularizacion --empresa=SDC. '
            .'Si no va a llegar, agrega --con-corte-actual para enviarla con la última carga.')
        ->assertSuccessful();

    expect(Run::query()->pluck('company')->all())->toBe([CompanyCode::SGX])
        ->and(collect($this->fakes->mail->sent())->every(fn (OutgoingMail $mail): bool => $mail->company === CompanyCode::SGX))->toBeTrue();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'Circularization held')
            && $context === ['company' => 'SDC', 'close' => '2026-11-30'])
        ->once();
});

it('fails when every company asked for is held back', function () {
    $this->artisan(SendCircularizationCommand::class)->assertFailed();

    expect(Run::query()->count())->toBe(0);
    $this->fakes->mail->assertNothingSent();
});

it('sends with the latest load when asked to, without a closing snapshot', function () {
    $this->artisan(SendCircularizationCommand::class, ['--empresa' => ['SGX'], '--con-corte-actual' => true])->assertSuccessful();

    $run = Run::query()->sole();

    expect($run->cutoff_date?->toDateString())->toBe('2026-12-06')
        ->and(collect($this->fakes->mail->sent())->pluck('subject')->unique()->values()->all())
        ->toBe(['Circularización de Cartera – Corte al 6 de diciembre de 2026'])
        ->and(VisibleText::of(collect($this->fakes->mail->sent())->sole(fn (OutgoingMail $mail): bool => $mail->clientId === $this->portfolio->tornillo->summary->id)->html))
        ->toContain('FV-1188');
});

it('previews the closing snapshot and says its cutoff', function () {
    cobroClosingSnapshot(CompanyCode::SGX);

    $this->actingAs(CobroUsers::create(Role::PortfolioAnalyst))->get('/cobro/circularizacion')->assertOk()
        ->assertSeeText('Corte de la cartera')
        ->assertSeeText('30/11/2026')
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertDontSeeText('Comercial Al Día SAS')
        ->assertDontSeeText('todavía no llega');
});

it('previews with the latest load while the closing snapshot has not arrived, and warns', function () {
    $this->actingAs(CobroUsers::create(Role::PortfolioAnalyst))->get('/cobro/circularizacion')->assertOk()
        ->assertSeeText('Todavía no llega la foto del cierre del 30/11/2026: esta vista previa usa la última carga, del 06/12/2026. El envío la espera.')
        ->assertSeeText('Comercial Al Día SAS');
});
