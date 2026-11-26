<?php

namespace App\Modules\Ingesta\Domain\Watch;

use DateTimeInterface;

/**
 * One scheduled load of etl/horarios.json: the time the robot runs, the
 * days it runs (every day when empty) and, optionally, what depends on it.
 * LoadSlots builds and validates it.
 */
final readonly class LoadSlot
{
    /** Cron day-of-week numbers of the day names of etl/horarios.json. */
    public const array DAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    /**
     * @param  string  $time  HH:MM in the slots' timezone.
     * @param  list<string>  $days  Keys of DAYS; empty for every day.
     * @param  string|null  $before  What runs after the load and uses it, in words.
     */
    public function __construct(
        public string $time,
        public array $days = [],
        public ?string $before = null,
    ) {}

    public function hour(): int
    {
        return (int) substr($this->time, 0, 2);
    }

    public function minute(): int
    {
        return (int) substr($this->time, 3, 2);
    }

    /**
     * The cron day-of-week numbers of the slot's days; empty for every day.
     *
     * @return list<int>
     */
    public function dayNumbers(): array
    {
        return array_map(fn (string $day): int => self::DAYS[$day], $this->days);
    }

    /**
     * Whether the slot runs on the day of $date.
     */
    public function runsOn(DateTimeInterface $date): bool
    {
        return $this->days === [] || in_array(strtolower($date->format('D')), $this->days, true);
    }

    /**
     * The HH:MM time $delay minutes after the slot, on the same day
     * (LoadSlots rejects a watch past midnight).
     */
    public function watchAt(int $delay): string
    {
        $minutes = $this->hour() * 60 + $this->minute() + $delay;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
