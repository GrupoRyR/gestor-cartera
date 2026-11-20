<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroPortfolio;

/*
 * The circularization waits for the closing snapshot of the month before:
 * a company whose snapshot has not arrived is not started, and its slot
 * stays free for a manual run once it arrives (unless --con-corte-actual;
 * see BusinessDayScheduleTest for the message and that option).
 */

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    // The 5th business day of November 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-31', 'America/Bogota');
});

it('sends in the same monthly run once the closing snapshot arrives later', function () {
    $this->artisan('cobro:circularizacion')->assertFailed();

    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'America/Bogota'));
    CobroPortfolio::closeMonth($this->fakes, 2026, 10, [CompanyCode::SGX]);

    $this->artisan('cobro:circularizacion --empresa=SGX')->assertSuccessful();

    $run = Run::query()->sole();

    expect($run->company)->toBe(CompanyCode::SGX)
        ->and($run->scheduled_for->equalTo(CarbonImmutable::parse('2026-11-09 09:00', 'America/Bogota')))->toBeTrue()
        ->and($run->cutoff_date?->toDateString())->toBe('2026-10-31')
        ->and(collect($this->fakes->mail->sent())->pluck('subject')->unique()->values()->all())
        ->toBe(['Circularización de Cartera – Corte al 31 de octubre de 2026']);
});

it('checks only the companies of --empresa', function () {
    CobroPortfolio::closeMonth($this->fakes, 2026, 10, [CompanyCode::SDC]);

    $this->artisan('cobro:circularizacion --empresa=SDC')
        ->doesntExpectOutputToContain('Segurex Latam')
        ->assertSuccessful();

    expect(Run::query()->pluck('company')->all())->toBe([CompanyCode::SDC]);
});

it('never lets a held company through when it is the one asked for', function () {
    CobroPortfolio::closeMonth($this->fakes, 2026, 10, [CompanyCode::SGX]);

    $this->artisan('cobro:circularizacion --empresa=SDC')->assertFailed();

    expect(Run::query()->count())->toBe(0);
});

it('still rejects a company that does not have the circularization', function () {
    $this->artisan('cobro:circularizacion --empresa=XYZ')
        ->expectsOutputToContain('La empresa XYZ no tiene este envío. Empresas válidas: SGX, SDC.')
        ->assertFailed();

    expect(Run::query()->count())->toBe(0);
});
