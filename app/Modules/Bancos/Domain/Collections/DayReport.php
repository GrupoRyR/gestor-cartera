<?php

namespace App\Modules\Bancos\Domain\Collections;

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * The daily collections report (informe de recaudo diario): what came in on
 * one day, by bank, for the companies in view. A bank without credits that
 * day still has its row, with nothing in it, so a missing statement shows.
 */
final readonly class DayReport
{
    /**
     * @param  list<CompanyCode>  $companies  Columns of the report, in CompanyCode order.
     * @param  array<string, array<string, float>>  $amounts  Bank value => company value => pesos.
     * @param  array<string, float>  $companyTotals  Company value => pesos.
     */
    private function __construct(
        public CarbonImmutable $date,
        public array $companies,
        private array $amounts,
        public array $companyTotals,
        public float $total,
        public int $movementCount,
    ) {}

    /**
     * @param  list<DailyCollection>  $days  What the provider gave for the date.
     * @param  list<CompanyCode>  $companies  The companies in view; anything else in $days is left out.
     */
    public static function from(CarbonImmutable $date, array $days, array $companies): self
    {
        $companies = array_values(array_filter(CompanyCode::cases(), fn (CompanyCode $code): bool => in_array($code, $companies, true)));

        $amounts = [];
        $companyTotals = array_fill_keys(array_map(fn (CompanyCode $code): string => $code->value, $companies), 0.0);
        $movements = 0;

        foreach ($days as $day) {
            if ($day->date->toDateString() !== $date->toDateString() || ! in_array($day->company, $companies, true)) {
                continue;
            }

            $companyTotals[$day->company->value] += $day->total;
            $movements += $day->movementCount;

            foreach ($day->byBank as $bank => $amount) {
                $amounts[$bank][$day->company->value] = ($amounts[$bank][$day->company->value] ?? 0.0) + $amount;
            }
        }

        return new self($date, $companies, $amounts, $companyTotals, array_sum($companyTotals), $movements);
    }

    /**
     * Pesos a bank brought in for a company that day.
     */
    public function amount(Bank $bank, CompanyCode $company): float
    {
        return $this->amounts[$bank->value][$company->value] ?? 0.0;
    }

    /**
     * Pesos a bank brought in that day, all companies in view together.
     */
    public function bankTotal(Bank $bank): float
    {
        return array_sum($this->amounts[$bank->value] ?? []);
    }

    /**
     * Share of the day's total a bank brought, 0 to 100.
     */
    public function bankShare(Bank $bank): float
    {
        return $this->total > 0 ? round($this->bankTotal($bank) / $this->total * 100, 1) : 0.0;
    }

    public function isEmpty(): bool
    {
        return $this->movementCount === 0;
    }
}
