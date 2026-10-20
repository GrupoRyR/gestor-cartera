<?php

namespace App\Modules\Gerencia\Domain\Period;

use Carbon\CarbonImmutable;

/**
 * A closed date range (both ends inclusive, at the start of their day) that
 * every figure of the dashboard is measured over.
 */
final readonly class ReportPeriod
{
    public function __construct(
        public PeriodKey $key,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
    ) {}

    public static function for(PeriodKey $key, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();

        return match ($key) {
            PeriodKey::Month => new self($key, $today->startOfMonth(), $today),
            PeriodKey::PreviousMonth => self::previousMonth($key, $today),
            // startOfMonth() first, so a 31st never overflows into the wrong month.
            PeriodKey::Quarter => new self($key, $today->startOfMonth()->subMonths(2), $today),
            PeriodKey::Year => new self($key, $today->startOfYear(), $today),
        };
    }

    /**
     * Calendar days in the period, both ends included.
     */
    public function days(): int
    {
        return (int) round($this->from->diffInDays($this->to, true)) + 1;
    }

    /**
     * Whether the calendar date of $date falls in the period; the time of
     * day is ignored.
     */
    public function contains(CarbonImmutable $date): bool
    {
        $day = $date->toDateString();

        return $day >= $this->from->toDateString() && $day <= $this->to->toDateString();
    }

    private static function previousMonth(PeriodKey $key, CarbonImmutable $today): self
    {
        $from = $today->startOfMonth()->subMonth();

        return new self($key, $from, $from->endOfMonth()->startOfDay());
    }
}
