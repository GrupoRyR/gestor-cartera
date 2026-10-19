<?php

use App\Modules\Bancos\Domain\Collections\CollectionBuilder;
use App\Modules\Bancos\Domain\Collections\DayBankTotal;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * Credits of a bank on a day (Bogotá), in pesos.
 */
function bancosTotal(string $date, Bank $bank, float $pesos, int $movements = 1, CompanyCode $company = CompanyCode::SGX): DayBankTotal
{
    return new DayBankTotal(CarbonImmutable::parse($date, 'America/Bogota')->startOfDay(), $company, $bank, (int) round($pesos * 100), $movements);
}

describe('daily', function () {
    it('adds the banks of a day and keeps what each bank brought', function () {
        $days = CollectionBuilder::daily([
            bancosTotal('2026-10-05', Bank::Bancolombia, 1_000_000, 2),
            bancosTotal('2026-10-05', Bank::Bogota, 250_000.5, 1),
        ]);

        expect($days)->toHaveCount(1)
            ->and($days[0]->total)->toBe(1_250_000.5)
            ->and($days[0]->byBank)->toBe(['bogota' => 250_000.5, 'bancolombia' => 1_000_000.0])
            ->and($days[0]->movementCount)->toBe(3)
            ->and($days[0]->date->toDateString())->toBe('2026-10-05');
    });

    it('lists the banks in the order of the Bank enum, whatever order they arrive in', function () {
        $days = CollectionBuilder::daily([
            bancosTotal('2026-10-05', Bank::Miami, 5),
            bancosTotal('2026-10-05', Bank::Davivienda, 4),
            bancosTotal('2026-10-05', Bank::Bogota, 3),
        ]);

        expect(array_keys($days[0]->byBank))->toBe(['bogota', 'davivienda', 'miami']);
    });

    it('splits a day by company and orders by date and then company', function () {
        $days = CollectionBuilder::daily([
            bancosTotal('2026-10-06', Bank::Bogota, 10, company: CompanyCode::SGX),
            bancosTotal('2026-10-05', Bank::Bogota, 20, company: CompanyCode::SDC),
            bancosTotal('2026-10-05', Bank::Bogota, 30, company: CompanyCode::SGX),
        ]);

        expect(array_map(fn ($day) => $day->date->toDateString().' '.$day->company->value, $days))
            ->toBe(['2026-10-05 SGX', '2026-10-05 SDC', '2026-10-06 SGX']);
    });

    it('is empty when there are no credits', function () {
        expect(CollectionBuilder::daily([]))->toBe([]);
    });

    it('adds cents without floating point drift', function () {
        $days = CollectionBuilder::daily(array_map(fn () => bancosTotal('2026-10-05', Bank::Bogota, 0.10), range(1, 10)));

        expect($days[0]->total)->toBe(1.0);
    });
});

describe('weekly', function () {
    it('adds the days of an ISO week, Monday to Sunday', function () {
        $weeks = CollectionBuilder::weekly(CollectionBuilder::daily([
            bancosTotal('2026-10-05', Bank::Bogota, 100),   // Monday
            bancosTotal('2026-10-11', Bank::Bancolombia, 50), // Sunday
            bancosTotal('2026-10-12', Bank::Bogota, 7),     // next Monday
        ]));

        expect($weeks)->toHaveCount(2)
            ->and($weeks[0]->weekStart->toDateString())->toBe('2026-10-05')
            ->and($weeks[0]->weekEnd->toDateString())->toBe('2026-10-11')
            ->and($weeks[0]->total)->toBe(150.0)
            ->and($weeks[0]->byBank)->toBe(['bogota' => 100.0, 'bancolombia' => 50.0])
            ->and($weeks[1]->weekStart->toDateString())->toBe('2026-10-12')
            ->and($weeks[1]->total)->toBe(7.0);
    });

    it('keeps the companies apart and leaves out weeks without credits', function () {
        $weeks = CollectionBuilder::weekly(CollectionBuilder::daily([
            bancosTotal('2026-10-05', Bank::Bogota, 1, company: CompanyCode::SDC),
            bancosTotal('2026-10-05', Bank::Bogota, 2, company: CompanyCode::SGX),
            bancosTotal('2026-10-26', Bank::Bogota, 3, company: CompanyCode::SGX),
        ]));

        expect(array_map(fn ($week) => $week->weekStart->toDateString().' '.$week->company->value, $weeks))
            ->toBe(['2026-10-05 SGX', '2026-10-05 SDC', '2026-10-26 SGX']);
    });

    it('crosses a year boundary the ISO way', function () {
        // 2026-12-31 is a Thursday: its week starts on Monday 2026-12-28 and ends on 2027-01-03.
        $weeks = CollectionBuilder::weekly(CollectionBuilder::daily([
            bancosTotal('2026-12-31', Bank::Bogota, 1),
            bancosTotal('2027-01-02', Bank::Bogota, 2),
        ]));

        expect($weeks)->toHaveCount(1)
            ->and($weeks[0]->weekStart->toDateString())->toBe('2026-12-28')
            ->and($weeks[0]->weekEnd->toDateString())->toBe('2027-01-03')
            ->and($weeks[0]->total)->toBe(3.0);
    });
});
