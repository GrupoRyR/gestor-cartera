<?php

namespace App\Modules\Comisiones\Domain\Parameters;

use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\Validity;
use InvalidArgumentException;
use LogicException;

/**
 * One version of the collection tranche table (§4 "TramoRecaudo", §5.3).
 *
 * The tranches cover every day count exactly once: the first starts at 0
 * days, each next one starts the day after the previous ends, and only the
 * last is open-ended.
 */
final readonly class TrancheTable
{
    /** @var list<CollectionTranche> Sorted by their first day. */
    public array $tranches;

    /**
     * @param  list<CollectionTranche>  $tranches  In any order.
     *
     * @throws InvalidArgumentException when the tranches leave a gap, overlap,
     *                                  do not start at 0 days or do not end open-ended.
     */
    public function __construct(
        public Validity $validity,
        array $tranches,
    ) {
        usort($tranches, fn (CollectionTranche $a, CollectionTranche $b): int => $a->fromDays <=> $b->fromDays);

        self::assertCoversEveryDay($tranches);

        $this->tranches = $tranches;
    }

    /**
     * The share of the collection rate for a payment made $days after its invoice.
     *
     * @throws InvalidArgumentException when the days are negative (§5.3 counts them as 0).
     */
    public function shareFor(int $days): Percentage
    {
        if ($days < 0) {
            throw new InvalidArgumentException("Collection days cannot be negative, got {$days}.");
        }

        foreach ($this->tranches as $tranche) {
            if ($tranche->covers($days)) {
                return $tranche->share;
            }
        }

        throw new LogicException("No tranche covers {$days} days.");
    }

    /**
     * @param  list<CollectionTranche>  $tranches  Sorted by their first day.
     */
    private static function assertCoversEveryDay(array $tranches): void
    {
        if ($tranches === []) {
            throw new InvalidArgumentException('A tranche table needs at least one tranche.');
        }

        if ($tranches[0]->fromDays !== 0) {
            throw new InvalidArgumentException("The first tranche must start at 0 days, got {$tranches[0]->fromDays}.");
        }

        for ($i = 1; $i < count($tranches); $i++) {
            $previous = $tranches[$i - 1];
            $current = $tranches[$i];

            if ($previous->toDays === null || $current->fromDays <= $previous->toDays) {
                throw new InvalidArgumentException(
                    "The tranches starting at {$previous->fromDays} and {$current->fromDays} days overlap.",
                );
            }

            if ($current->fromDays !== $previous->toDays + 1) {
                throw new InvalidArgumentException(
                    "There is a gap between {$previous->toDays} and {$current->fromDays} days.",
                );
            }
        }

        if ($tranches[count($tranches) - 1]->toDays !== null) {
            throw new InvalidArgumentException('The last tranche must be open-ended.');
        }
    }
}
