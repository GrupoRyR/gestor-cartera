<?php

namespace App\Modules\Cartera\Application\Commitments;

use App\Modules\Core\Contracts\Enums\CommitmentStatus;

/**
 * The tabs of the commitments inbox: one per status, and all of them.
 */
enum CommitmentTab: string
{
    case Pending = 'pendientes';

    case Broken = 'incumplidos';

    case Fulfilled = 'cumplidos';

    case Cancelled = 'anulados';

    case All = 'todos';

    /**
     * The statuses the tab lists; empty means every status.
     *
     * @return list<CommitmentStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Pending => [CommitmentStatus::Pending],
            self::Broken => [CommitmentStatus::Broken],
            self::Fulfilled => [CommitmentStatus::Fulfilled],
            self::Cancelled => [CommitmentStatus::Cancelled],
            self::All => [],
        };
    }
}
