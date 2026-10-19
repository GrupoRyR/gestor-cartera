<?php

namespace App\Modules\Bancos\Application\Reports;

use App\Modules\Bancos\Domain\Collections\DayReport;
use App\Modules\Bancos\Domain\Collections\WeekReport;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\RecaudoProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The two collections reports of Cartera, built from the RecaudoProvider
 * contract (the same numbers Treasury reads from the API): the day, by bank,
 * and the week with its running total.
 */
final class CollectionReports
{
    public function __construct(private readonly RecaudoProvider $collections) {}

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
     * The week of $date (Monday to Sunday) and how it compares with the one before.
     *
     * @param  list<CompanyCode>  $companies  The companies in view.
     */
    public function week(CarbonImmutable $date, array $companies): WeekReport
    {
        $start = $date->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
        $previous = $start->subWeek();

        return WeekReport::from(
            anyDayOfTheWeek: $date,
            thisWeek: $this->collections->daily($start, $start->addDays(6)),
            previousWeek: $this->collections->daily($previous, $previous->addDays(6)),
            companies: $companies,
            lastLoaded: self::defaultDay(),
        );
    }
}
