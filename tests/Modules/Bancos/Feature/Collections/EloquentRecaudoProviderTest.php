<?php

use App\Modules\Bancos\Infrastructure\EloquentRecaudoProvider;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\RecaudoProvider;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeRecaudoProvider;

/**
 * A stored credit.
 */
function bancosCredit(string $date, string $amount, Bank $bank = Bank::Bogota, CompanyCode $company = CompanyCode::SGX, string $currency = 'COP'): BankMovement
{
    return BankMovement::factory()->forCompany($company)->inBank($bank)->on($date)->worth($amount)->create(['currency' => $currency]);
}

function bancosDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/Bogota')->startOfDay();
}

it('is the RecaudoProvider of the container', function () {
    expect(app(RecaudoProvider::class))->toBeInstanceOf(EloquentRecaudoProvider::class);
});

describe('daily', function () {
    it('adds the credits of each day, bank and company', function () {
        bancosCredit('2026-10-05', '1000000.00', Bank::Bogota);
        bancosCredit('2026-10-05', '250000.50', Bank::Bogota);
        bancosCredit('2026-10-05', '300000.00', Bank::Bancolombia);
        bancosCredit('2026-10-05', '999.00', Bank::Bogota, CompanyCode::SDC);
        bancosCredit('2026-10-06', '10.00');

        $days = app(RecaudoProvider::class)->daily(bancosDay('2026-10-01'), bancosDay('2026-10-31'));

        expect($days)->toHaveCount(3)
            ->and($days[0])->toBeInstanceOf(DailyCollection::class)
            ->and($days[0]->date->toDateString())->toBe('2026-10-05')
            ->and($days[0]->company)->toBe(CompanyCode::SGX)
            ->and($days[0]->total)->toBe(1_550_000.5)
            ->and($days[0]->byBank)->toBe(['bogota' => 1_250_000.5, 'bancolombia' => 300_000.0])
            ->and($days[0]->movementCount)->toBe(3)
            ->and($days[1]->company)->toBe(CompanyCode::SDC)
            ->and($days[1]->total)->toBe(999.0)
            ->and($days[2]->date->toDateString())->toBe('2026-10-06');
    });

    it('includes both ends of the range', function () {
        bancosCredit('2026-10-05', '1.00');
        bancosCredit('2026-10-06', '2.00');
        bancosCredit('2026-10-07', '4.00');
        bancosCredit('2026-10-04', '8.00');
        bancosCredit('2026-10-08', '16.00');

        $days = app(RecaudoProvider::class)->daily(bancosDay('2026-10-05'), bancosDay('2026-10-07'));

        expect(array_map(fn ($day) => $day->total, $days))->toBe([1.0, 2.0, 4.0]);
    });

    it('filters by company', function () {
        bancosCredit('2026-10-05', '1.00', company: CompanyCode::SGX);
        bancosCredit('2026-10-05', '2.00', company: CompanyCode::SDC);

        $days = app(RecaudoProvider::class)->daily(bancosDay('2026-10-05'), bancosDay('2026-10-05'), CompanyCode::SDC);

        expect($days)->toHaveCount(1)->and($days[0]->total)->toBe(2.0);
    });

    it('counts every status: an ignored credit still reached the bank', function () {
        BankMovement::factory()->on('2026-10-05')->worth('100.00')->create();
        BankMovement::factory()->on('2026-10-05')->worth('200.00')->applied()->create();
        BankMovement::factory()->on('2026-10-05')->worth('400.00')->ignored()->create();

        $days = app(RecaudoProvider::class)->daily(bancosDay('2026-10-05'), bancosDay('2026-10-05'));

        expect($days[0]->total)->toBe(700.0);
    });

    it('adds only pesos: a dollar account has no exchange rate to convert it', function () {
        bancosCredit('2026-10-05', '1000.00');
        bancosCredit('2026-10-05', '5000.00', Bank::Miami, currency: 'USD');

        $provider = app(EloquentRecaudoProvider::class);
        $days = $provider->daily(bancosDay('2026-10-05'), bancosDay('2026-10-05'));

        expect($days[0]->total)->toBe(1000.0)
            ->and($days[0]->byBank)->toBe(['bogota' => 1000.0])
            ->and($provider->excludedMovements(bancosDay('2026-10-05'), bancosDay('2026-10-05')))->toBe(1);
    });

    it('is empty when nothing was loaded', function () {
        expect(app(RecaudoProvider::class)->daily(bancosDay('2026-10-01'), bancosDay('2026-10-31')))->toBe([]);
    });

    it('reads a date stored with a time of day the same as one without', function () {
        // Eloquent stores the date column as a datetime on SQLite and as a date on MySQL.
        BankMovement::factory()->on(CarbonImmutable::parse('2026-10-05 00:00:00'))->worth('1.00')->create();
        BankMovement::factory()->on('2026-10-05')->worth('2.00')->create();

        $days = app(RecaudoProvider::class)->daily(bancosDay('2026-10-05'), bancosDay('2026-10-05'));

        expect($days)->toHaveCount(1)->and($days[0]->total)->toBe(3.0);
    });

    it('refuses a range that ends before it starts', function () {
        expect(fn () => app(RecaudoProvider::class)->daily(bancosDay('2026-10-07'), bancosDay('2026-10-05')))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('weekly', function () {
    it('groups the days by ISO week and company', function () {
        bancosCredit('2026-10-05', '100.00');           // Monday
        bancosCredit('2026-10-11', '50.00', Bank::Bancolombia); // Sunday
        bancosCredit('2026-10-12', '7.00');             // next Monday

        $weeks = app(RecaudoProvider::class)->weekly(bancosDay('2026-10-01'), bancosDay('2026-10-31'));

        expect($weeks)->toHaveCount(2)
            ->and($weeks[0]->weekStart->toDateString())->toBe('2026-10-05')
            ->and($weeks[0]->weekEnd->toDateString())->toBe('2026-10-11')
            ->and($weeks[0]->total)->toBe(150.0)
            ->and($weeks[1]->total)->toBe(7.0);
    });

    it('sums only the days inside the range, so the first week can be partial', function () {
        bancosCredit('2026-10-05', '100.00');
        bancosCredit('2026-10-07', '10.00');

        $weeks = app(RecaudoProvider::class)->weekly(bancosDay('2026-10-07'), bancosDay('2026-10-31'));

        expect($weeks)->toHaveCount(1)->and($weeks[0]->total)->toBe(10.0);
    });
});

it('gives what the contract fake gives for the same credits', function () {
    bancosCredit('2026-10-05', '1000000.00', Bank::Bogota, CompanyCode::SGX);
    bancosCredit('2026-10-05', '250000.50', Bank::Bancolombia, CompanyCode::SGX);
    bancosCredit('2026-10-07', '777.00', Bank::Davivienda, CompanyCode::SDC);
    bancosCredit('2026-10-14', '5.00', Bank::Bogota, CompanyCode::SGX);

    $fake = (new FakeRecaudoProvider)->addDaily(
        new DailyCollection(bancosDay('2026-10-05'), CompanyCode::SGX, 1_250_000.5, ['bogota' => 1_000_000.0, 'bancolombia' => 250_000.5], 2),
        new DailyCollection(bancosDay('2026-10-07'), CompanyCode::SDC, 777.0, ['davivienda' => 777.0], 1),
        new DailyCollection(bancosDay('2026-10-14'), CompanyCode::SGX, 5.0, ['bogota' => 5.0], 1),
    );

    $real = app(RecaudoProvider::class);
    $from = bancosDay('2026-10-01');
    $to = bancosDay('2026-10-31');

    expect($real->daily($from, $to))->toEqual($fake->daily($from, $to))
        ->and($real->weekly($from, $to))->toEqual($fake->weekly($from, $to))
        ->and($real->weekly($from, $to, CompanyCode::SDC))->toEqual($fake->weekly($from, $to, CompanyCode::SDC));
});
