<?php

namespace App\Modules\Comisiones\Domain\Shared;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A settlement period: one calendar month (§5.1). Sales belong to the period
 * of their document date; payments and crossings to the period of their
 * payment or crossing date.
 */
final readonly class Period
{
    /**
     * @throws InvalidArgumentException when the year has not four digits or the month is not 1–12.
     */
    public function __construct(
        public int $year,
        public int $month,
    ) {
        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException("A period year must have four digits, got {$year}.");
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("A period month must be between 1 and 12, got {$month}.");
        }
    }

    public static function of(int $year, int $month): self
    {
        return new self($year, $month);
    }

    /**
     * From its key, "2026-07".
     *
     * @throws InvalidArgumentException when the key is not "YYYY-MM".
     */
    public static function fromString(string $key): self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $key, $matches) !== 1) {
            throw new InvalidArgumentException("\"{$key}\" is not a period key (YYYY-MM).");
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** The first day of the month, at 00:00 UTC. */
    public function start(): DateTimeImmutable
    {
        return Dates::day($this->key().'-01');
    }

    /** The last day of the month, at 00:00 UTC. */
    public function end(): DateTimeImmutable
    {
        $start = $this->start();

        return $start->setDate($this->year, $this->month, (int) $start->format('t'));
    }

    /** Whether the calendar day of the date falls in this month. */
    public function contains(DateTimeImmutable $date): bool
    {
        $day = Dates::day($date);

        return $day >= $this->start() && $day <= $this->end();
    }

    public function equals(Period $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }

    public function previous(): self
    {
        return $this->month === 1 ? new self($this->year - 1, 12) : new self($this->year, $this->month - 1);
    }

    public function next(): self
    {
        return $this->month === 12 ? new self($this->year + 1, 1) : new self($this->year, $this->month + 1);
    }
}
