<?php

namespace App\Modules\Core\Domain\Status;

use Carbon\CarbonImmutable;

/**
 * One open document of a client in a receivables snapshot, reduced to what
 * the status rules need.
 */
final readonly class OpenItem
{
    /**
     * @param  float  $balance  Amount still owed; negative for a credit note.
     * @param  int  $daysOverdue  Calendar days from the due date to the snapshot cutoff:
     *                            positive = past due, 0 = due on the cutoff, negative =
     *                            not yet due (same convention as Invoice::$daysOverdue).
     */
    public function __construct(
        public float $balance,
        public int $daysOverdue,
    ) {}

    /**
     * Builds an item from its due date and the snapshot cutoff.
     *
     * Only the calendar date of each value counts (each in its own time zone),
     * so the time of day never shifts the result.
     */
    public static function fromDates(float $balance, CarbonImmutable $dueAt, CarbonImmutable $cutoff): self
    {
        // Both dates at midnight UTC: whole days apart, with no DST to skew them.
        $due = CarbonImmutable::parse($dueAt->toDateString(), 'UTC');
        $cut = CarbonImmutable::parse($cutoff->toDateString(), 'UTC');

        return new self($balance, (int) $due->diffInDays($cut, false));
    }
}
