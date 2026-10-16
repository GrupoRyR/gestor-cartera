<?php

namespace App\Modules\Comisiones\Domain\Shared;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The days a parameter version is in force (§4: every commission parameter
 * has a validity). Both ends are inclusive; no end means open-ended.
 */
final readonly class Validity
{
    public DateTimeImmutable $from;

    public ?DateTimeImmutable $until;

    /**
     * @throws InvalidArgumentException when a date is invalid or the end is before the start.
     */
    public function __construct(DateTimeImmutable $from, ?DateTimeImmutable $until = null)
    {
        $this->from = Dates::day($from);
        $this->until = $until === null ? null : Dates::day($until);

        if ($this->until !== null && $this->until < $this->from) {
            throw new InvalidArgumentException(sprintf(
                'A validity cannot end (%s) before it starts (%s).',
                $this->until->format('Y-m-d'),
                $this->from->format('Y-m-d'),
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when a date is invalid or the end is before the start.
     */
    public static function from(
        string|DateTimeImmutable $from,
        string|DateTimeImmutable|null $until = null,
    ): self {
        return new self(Dates::day($from), $until === null ? null : Dates::day($until));
    }

    /** Whether the version is in force on at least one day of the period. */
    public function overlaps(Period $period): bool
    {
        return $this->from <= $period->end()
            && ($this->until === null || $this->until >= $period->start());
    }

    /** Whether both validities share at least one day. */
    public function overlapsValidity(Validity $other): bool
    {
        return ($other->until === null || $this->from <= $other->until)
            && ($this->until === null || $other->from <= $this->until);
    }

    /** Whether the calendar day of the date is in force. */
    public function contains(DateTimeImmutable $date): bool
    {
        $day = Dates::day($date);

        return $day >= $this->from && ($this->until === null || $day <= $this->until);
    }
}
