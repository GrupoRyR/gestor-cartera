<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Collections (recaudo): the sum of bank credits per movement date.
 *
 * Implemented by Bancos (phase 1); tests use Tests\Fakes\FakeRecaudoProvider.
 *
 * Bank movements are loaded the next day (día vencido), so the current day
 * is normally missing. Not user-scoped: callers authorize, e.g. the
 * read-only GET /api/recaudo for Treasury or the management dashboard.
 * Dates are compared by calendar day in America/Bogota; both ends of the
 * range are inclusive. Amounts are Colombian pesos.
 */
interface RecaudoProvider
{
    /**
     * One entry per day and company with at least one credit, ordered by
     * date and then by company; null company means every company.
     *
     * @return list<DailyCollection>
     *
     * @throws InvalidArgumentException When $from is after $to.
     */
    public function daily(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array;

    /**
     * The daily figures grouped by ISO week (Monday to Sunday) and company,
     * ordered by week and then by company; null company means every company.
     * Only days inside the range are summed, so the first and last weeks may
     * be partial; weeks without credits are left out.
     *
     * @return list<WeeklyCollection>
     *
     * @throws InvalidArgumentException When $from is after $to.
     */
    public function weekly(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array;
}
