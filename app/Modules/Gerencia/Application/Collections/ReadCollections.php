<?php

namespace App\Modules\Gerencia\Application\Collections;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\RecaudoProvider;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;

/**
 * Reads the bank collections of a period. RecaudoProvider does not filter by
 * user, so each company is asked for explicitly and only the ones in the
 * user's scope are read.
 */
final class ReadCollections
{
    public function __construct(
        private readonly RecaudoProvider $recaudo,
        private readonly CurrentCompany $company,
    ) {}

    public function handle(ReportPeriod $period): CollectionsReport
    {
        $daily = [];
        $weekly = [];

        foreach ($this->company->scope() as $company) {
            $daily = [...$daily, ...$this->recaudo->daily($period->from, $period->to, $company->code)];
            $weekly = [...$weekly, ...$this->recaudo->weekly($period->from, $period->to, $company->code)];
        }

        usort($daily, fn (DailyCollection $a, DailyCollection $b): int => [$a->date->toDateString(), self::order($a->company)]
            <=> [$b->date->toDateString(), self::order($b->company)]);
        usort($weekly, fn (WeeklyCollection $a, WeeklyCollection $b): int => [$a->weekStart->toDateString(), self::order($a->company)]
            <=> [$b->weekStart->toDateString(), self::order($b->company)]);

        return new CollectionsReport($daily, $weekly);
    }

    private static function order(CompanyCode $company): int
    {
        return (int) array_search($company, CompanyCode::cases(), true);
    }
}
