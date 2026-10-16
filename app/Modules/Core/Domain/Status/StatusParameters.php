<?php

namespace App\Modules\Core\Domain\Status;

use InvalidArgumentException;

/**
 * The company parameters that drive the automatic client statuses.
 */
final readonly class StatusParameters
{
    /**
     * @param  int  $graceDays  Days after an invoice's due date before it counts as
     *                          overdue for the client's status (Company::$graceDays).
     * @param  int  $dueSoonDays  How many days before its due date an invoice makes
     *                            the client "due soon".
     *
     * @throws InvalidArgumentException when either value is negative.
     */
    public function __construct(
        public int $graceDays,
        public int $dueSoonDays,
    ) {
        if ($graceDays < 0) {
            throw new InvalidArgumentException("Grace days must be zero or more, got {$graceDays}.");
        }

        if ($dueSoonDays < 0) {
            throw new InvalidArgumentException("Due soon days must be zero or more, got {$dueSoonDays}.");
        }
    }
}
