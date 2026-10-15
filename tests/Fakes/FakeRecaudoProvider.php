<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\RecaudoProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * In-memory RecaudoProvider fed with addDaily(); weekly() adds the daily
 * figures by ISO week (Monday to Sunday) and company.
 */
final class FakeRecaudoProvider implements RecaudoProvider
{
    /**
     * @var list<DailyCollection>
     */
    private array $days = [];

    public function addDaily(DailyCollection ...$days): static
    {
        $this->days = [...$this->days, ...array_values($days)];

        return $this;
    }

    public function daily(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array
    {
        $this->guardRange($from, $to);

        $days = array_values(array_filter(
            $this->days,
            fn (DailyCollection $day): bool => $day->date->toDateString() >= $from->toDateString()
                && $day->date->toDateString() <= $to->toDateString()
                && ($company === null || $day->company === $company),
        ));

        usort($days, fn (DailyCollection $a, DailyCollection $b): int => [$a->date->toDateString(), $this->companyOrder($a->company)]
            <=> [$b->date->toDateString(), $this->companyOrder($b->company)]);

        return $days;
    }

    public function weekly(CarbonImmutable $from, CarbonImmutable $to, ?CompanyCode $company = null): array
    {
        /** @var array<string, array{start: CarbonImmutable, company: CompanyCode, total: float, byBank: array<string, float>}> $weeks */
        $weeks = [];

        foreach ($this->daily($from, $to, $company) as $day) {
            $start = $day->date->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
            $key = $start->toDateString().'|'.$this->companyOrder($day->company);

            $weeks[$key] ??= ['start' => $start, 'company' => $day->company, 'total' => 0.0, 'byBank' => []];
            $weeks[$key]['total'] += $day->total;

            foreach ($day->byBank as $bank => $amount) {
                $weeks[$key]['byBank'][$bank] = ($weeks[$key]['byBank'][$bank] ?? 0.0) + $amount;
            }
        }

        ksort($weeks);

        return array_values(array_map(
            fn (array $week): WeeklyCollection => new WeeklyCollection(
                weekStart: $week['start'],
                weekEnd: $week['start']->addDays(6),
                company: $week['company'],
                total: $week['total'],
                byBank: $week['byBank'],
            ),
            $weeks,
        ));
    }

    private function guardRange(CarbonImmutable $from, CarbonImmutable $to): void
    {
        if ($from->toDateString() > $to->toDateString()) {
            throw new InvalidArgumentException("The range ends ({$to->toDateString()}) before it starts ({$from->toDateString()}).");
        }
    }

    /**
     * Position of the company in CompanyCode case order.
     */
    private function companyOrder(CompanyCode $company): int
    {
        return (int) array_search($company, CompanyCode::cases(), true);
    }
}
