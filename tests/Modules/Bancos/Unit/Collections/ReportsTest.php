<?php

use App\Modules\Bancos\Domain\Collections\DayReport;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

function bancosReportDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'America/Bogota')->startOfDay();
}

/**
 * @param  array<string, float>  $byBank
 */
function bancosCollected(string $date, float $total, array $byBank, CompanyCode $company = CompanyCode::SGX, int $movements = 1): DailyCollection
{
    return new DailyCollection(bancosReportDay($date), $company, $total, $byBank, $movements);
}

describe('the daily report', function () {
    it('has a row per bank, with what each brought and its share of the day', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [
            bancosCollected('2026-10-15', 1000, ['bogota' => 750, 'bancolombia' => 250], movements: 4),
        ], [CompanyCode::SGX]);

        expect($report->total)->toBe(1000.0)
            ->and($report->movementCount)->toBe(4)
            ->and($report->amount(Bank::Bogota, CompanyCode::SGX))->toBe(750.0)
            ->and($report->bankTotal(Bank::Bancolombia))->toBe(250.0)
            ->and($report->bankShare(Bank::Bogota))->toBe(75.0)
            ->and($report->bankTotal(Bank::Miami))->toBe(0.0)
            ->and($report->bankShare(Bank::Miami))->toBe(0.0)
            ->and($report->isEmpty())->toBeFalse();
    });

    it('shows both companies side by side in the group view', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [
            bancosCollected('2026-10-15', 600, ['bogota' => 600], CompanyCode::SDC),
            bancosCollected('2026-10-15', 400, ['bogota' => 100, 'miami' => 300], CompanyCode::SGX),
        ], [CompanyCode::SDC, CompanyCode::SGX]);

        expect($report->companies)->toBe([CompanyCode::SGX, CompanyCode::SDC])
            ->and($report->companyTotals)->toBe(['SGX' => 400.0, 'SDC' => 600.0])
            ->and($report->total)->toBe(1000.0)
            ->and($report->bankTotal(Bank::Bogota))->toBe(700.0)
            ->and($report->amount(Bank::Bogota, CompanyCode::SDC))->toBe(600.0);
    });

    it('leaves out a company that is not in view and a day that is not the one asked for', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [
            bancosCollected('2026-10-15', 100, ['bogota' => 100], CompanyCode::SGX),
            bancosCollected('2026-10-15', 999, ['bogota' => 999], CompanyCode::SDC),
            bancosCollected('2026-10-14', 888, ['bogota' => 888], CompanyCode::SGX),
        ], [CompanyCode::SGX]);

        expect($report->total)->toBe(100.0)
            ->and($report->companies)->toBe([CompanyCode::SGX]);
    });

    it('is empty when nothing came in', function () {
        $report = DayReport::from(bancosReportDay('2026-10-15'), [], [CompanyCode::SGX]);

        expect($report->isEmpty())->toBeTrue()
            ->and($report->total)->toBe(0.0)
            ->and($report->bankShare(Bank::Bogota))->toBe(0.0);
    });
});
