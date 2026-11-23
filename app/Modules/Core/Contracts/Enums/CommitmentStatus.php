<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Status of a payment commitment (compromiso de pago).
 *
 * Every commitment starts Pending. Core's daily task turns a Pending
 * commitment whose due date has passed without payment into Broken.
 * Fulfilled, Cancelled and PartiallyPaid are final (see CompromisoService).
 */
enum CommitmentStatus: string
{
    use HasOptions;

    /** Created and not yet due, or due today. */
    case Pending = 'pendiente';

    /** The client paid as promised (final). */
    case Fulfilled = 'cumplido';

    /** The due date passed without payment. */
    case Broken = 'incumplido';

    /** Voided by a user, with a reason (final). */
    case Cancelled = 'anulado';

    /**
     * The client paid part of the amount and the rest stays open as
     * ordinary overdue debt (final). The paid amount is stored; the
     * remaining balance is derived (Commitment::remaining()).
     */
    case PartiallyPaid = 'abonado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Fulfilled => 'Cumplido',
            self::Broken => 'Incumplido',
            self::Cancelled => 'Anulado',
            self::PartiallyPaid => 'Abonado',
        };
    }
}
