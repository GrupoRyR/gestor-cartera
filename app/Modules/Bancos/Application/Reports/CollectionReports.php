<?php

namespace App\Modules\Bancos\Application\Reports;

use App\Modules\Bancos\Domain\Calendar\ColombianHolidays;
use App\Modules\Bancos\Domain\Collections\DayReport;
use App\Modules\Bancos\Domain\Collections\MonthReport;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\RecaudoProvider;
use Carbon\CarbonImmutable;

/**
 * The two collections reports of Cartera, built from the RecaudoProvider
 * contract (the same numbers Treasury reads from the API): the day, by bank,
 * and the month as the analyst's grid of business days.
 */
final class CollectionReports
{
    public function __construct(
        private readonly RecaudoProvider $collections,
        private readonly ColombianHolidays $holidays,
    ) {}

    /**
     * The day the report opens on: yesterday. The movements arrive the day
     * after (día vencido), so today normally has no figures yet.
     */
    public static function defaultDay(): CarbonImmutable
    {
        return CarbonImmutable::now(Cells::TIMEZONE)->subDay()->startOfDay();
    }

    /**
     * @param  list<CompanyCode>  $companies  The companies in view.
     */
    public function day(CarbonImmutable $date, array $companies): DayReport
    {
        return DayReport::from($date, $this->collections->daily($date, $date), $companies);
    }

    /**
     * The month of $date, a grid per company: the weekend and holiday credits
     * go to the next business day, even when that is in the next month.
     *
     * @param  list<CompanyCode>  $companies  The companies in view.
     */
    public function month(CarbonImmutable $date, array $companies): MonthReport
    {
        [$from, $to] = MonthReport::range($date, $this->holidays);

        return MonthReport::from($date, $this->collections->daily($from, $to), $companies, self::defaultDay(), $this->holidays);
    }
}
