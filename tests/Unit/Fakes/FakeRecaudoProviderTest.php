<?php

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeRecaudoProvider;

function collectionDay(string $date, CompanyCode $company, float $bogota, float $bancolombia = 0.0): DailyCollection
{
    $byBank = array_filter(['bogota' => $bogota, 'bancolombia' => $bancolombia]);

    return new DailyCollection(CarbonImmutable::parse($date), $company, $bogota + $bancolombia, $byBank, count($byBank));
}

beforeEach(function () {
    // 2026-10-05 is a Monday; 2026-10-11 is the Sunday of that ISO week.
    $this->provider = (new FakeRecaudoProvider)->addDaily(
        collectionDay('2026-10-12', CompanyCode::SGX, 100),
        collectionDay('2026-10-05', CompanyCode::SGX, 10, 5),
        collectionDay('2026-10-11', CompanyCode::SGX, 20),
        collectionDay('2026-10-06', CompanyCode::SDC, 7),
        collectionDay('2026-10-04', CompanyCode::SGX, 1000),
    );
});

it('returns the days inside the range ordered by date and company', function () {
    $days = $this->provider->daily(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-11 18:00'));

    expect(array_map(fn (DailyCollection $day): string => $day->date->toDateString().' '.$day->company->value, $days))
        ->toBe(['2026-10-05 SGX', '2026-10-06 SDC', '2026-10-11 SGX'])
        ->and($this->provider->daily(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), CompanyCode::SDC))
        ->toHaveCount(1);
});

it('adds the days of each ISO week per company', function () {
    $weeks = $this->provider->weekly(CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-12'));

    expect($weeks)->toEqual([
        new WeeklyCollection(
            weekStart: CarbonImmutable::parse('2026-10-05'),
            weekEnd: CarbonImmutable::parse('2026-10-11'),
            company: CompanyCode::SGX,
            total: 35.0,
            byBank: ['bogota' => 30.0, 'bancolombia' => 5.0],
        ),
        new WeeklyCollection(
            weekStart: CarbonImmutable::parse('2026-10-05'),
            weekEnd: CarbonImmutable::parse('2026-10-11'),
            company: CompanyCode::SDC,
            total: 7.0,
            byBank: ['bogota' => 7.0],
        ),
        new WeeklyCollection(
            weekStart: CarbonImmutable::parse('2026-10-12'),
            weekEnd: CarbonImmutable::parse('2026-10-18'),
            company: CompanyCode::SGX,
            total: 100.0,
            byBank: ['bogota' => 100.0],
        ),
    ]);
});

it('sums only the days inside the range of a partial week', function () {
    $weeks = $this->provider->weekly(CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-11'), CompanyCode::SGX);

    expect($weeks)->toHaveCount(1)
        ->and($weeks[0]->weekStart->toDateString())->toBe('2026-10-05')
        ->and($weeks[0]->total)->toBe(20.0);
});

it('rejects a range that ends before it starts', function (string $method) {
    expect(fn () => $this->provider->{$method}(CarbonImmutable::parse('2026-10-10'), CarbonImmutable::parse('2026-10-09')))
        ->toThrow(InvalidArgumentException::class);
})->with(['daily', 'weekly']);
