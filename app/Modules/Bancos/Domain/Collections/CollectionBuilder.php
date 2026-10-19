<?php

namespace App\Modules\Bancos\Domain\Collections;

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Builds the collections (recaudo) the RecaudoProvider contract describes
 * from the credits added up per day, bank and company: per day and company,
 * then per ISO week (Monday to Sunday) and company.
 *
 * Everything is added in cents and divided once at the end, so a week is
 * exactly the sum of its days.
 */
final class CollectionBuilder
{
    /**
     * One entry per day and company with credits, ordered by date and then
     * company; the banks of an entry follow the order of the Bank enum.
     *
     * @param  list<DayBankTotal>  $totals
     * @return list<DailyCollection>
     */
    public static function daily(array $totals): array
    {
        /** @var array<string, array{date: CarbonImmutable, company: CompanyCode, cents: int, movements: int, banks: array<string, int>}> $days */
        $days = [];

        foreach ($totals as $total) {
            $key = sprintf('%s|%02d', $total->date->toDateString(), self::companyOrder($total->company));

            $days[$key] ??= ['date' => $total->date, 'company' => $total->company, 'cents' => 0, 'movements' => 0, 'banks' => []];
            $days[$key]['cents'] += $total->cents;
            $days[$key]['movements'] += $total->movements;
            $days[$key]['banks'][$total->bank->value] = ($days[$key]['banks'][$total->bank->value] ?? 0) + $total->cents;
        }

        ksort($days);

        return array_values(array_map(
            fn (array $day): DailyCollection => new DailyCollection(
                date: $day['date'],
                company: $day['company'],
                total: $day['cents'] / 100.0,
                byBank: self::inBankOrder($day['banks']),
                movementCount: $day['movements'],
            ),
            $days,
        ));
    }

    /**
     * The daily figures grouped by week and company, ordered by week and then
     * company. Only the days given are added, so a partial first or last week
     * stays partial.
     *
     * @param  list<DailyCollection>  $days
     * @return list<WeeklyCollection>
     */
    public static function weekly(array $days): array
    {
        /** @var array<string, array{start: CarbonImmutable, company: CompanyCode, cents: int, banks: array<string, int>}> $weeks */
        $weeks = [];

        foreach ($days as $day) {
            $start = $day->date->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
            $key = sprintf('%s|%02d', $start->toDateString(), self::companyOrder($day->company));

            $weeks[$key] ??= ['start' => $start, 'company' => $day->company, 'cents' => 0, 'banks' => []];
            $weeks[$key]['cents'] += self::cents($day->total);

            foreach ($day->byBank as $bank => $amount) {
                $weeks[$key]['banks'][$bank] = ($weeks[$key]['banks'][$bank] ?? 0) + self::cents($amount);
            }
        }

        ksort($weeks);

        return array_values(array_map(
            fn (array $week): WeeklyCollection => new WeeklyCollection(
                weekStart: $week['start'],
                weekEnd: $week['start']->addDays(6),
                company: $week['company'],
                total: $week['cents'] / 100.0,
                byBank: self::inBankOrder($week['banks']),
            ),
            $weeks,
        ));
    }

    /**
     * @param  array<string, int>  $cents  Bank value => cents.
     * @return array<string, float>
     */
    private static function inBankOrder(array $cents): array
    {
        $ordered = [];

        foreach (Bank::cases() as $bank) {
            if (isset($cents[$bank->value])) {
                $ordered[$bank->value] = $cents[$bank->value] / 100.0;
            }
        }

        return $ordered;
    }

    private static function cents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    private static function companyOrder(CompanyCode $company): int
    {
        return (int) array_search($company, CompanyCode::cases(), true);
    }
}
