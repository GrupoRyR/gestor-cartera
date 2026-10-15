<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Outcome of a load in the load history.
 */
enum LoadStatus: string
{
    use HasOptions;

    /** Validated and stored as a dated snapshot; BatchAccepted was dispatched. */
    case Accepted = 'aceptada';

    /** Failed validation; nothing was stored except the history entry. */
    case Rejected = 'rechazada';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => 'Aceptada',
            self::Rejected => 'Rechazada',
        };
    }
}
