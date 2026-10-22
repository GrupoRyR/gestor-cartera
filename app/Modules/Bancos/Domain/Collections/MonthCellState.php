<?php

namespace App\Modules\Bancos\Domain\Collections;

/**
 * What a weekday cell of the monthly grid holds.
 */
enum MonthCellState
{
    /** The weekday belongs to the month before or after: an empty, shaded cell. */
    case Outside;

    /** A Colombian holiday: its credits went to the next business day. */
    case Holiday;

    /** A business day whose statements have not arrived yet (after yesterday): no figure, not zero. */
    case Pending;

    /** A business day with its figure, which may be zero. */
    case Loaded;
}
