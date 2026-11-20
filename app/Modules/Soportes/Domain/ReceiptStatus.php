<?php

namespace App\Modules\Soportes\Domain;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;
use App\Modules\Soportes\Domain\Exceptions\InvalidReceiptTransition;

/**
 * Where a payment receipt stands with the portfolio analyst: waiting to be
 * applied in SAP, applied, or rejected (with a reason the advisor sees).
 *
 * Applied and rejected are final: a correction is a new receipt. The app
 * never marks an invoice as paid; SAP's next snapshot does.
 */
enum ReceiptStatus: string
{
    use HasOptions;

    case Pending = 'pendiente';

    case Applied = 'aplicado';

    case Rejected = 'rechazado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Applied => 'Aplicado',
            self::Rejected => 'Rechazado',
        };
    }

    /**
     * Chip tone of the status in the lists (see <x-ui.chip>).
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Applied => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * Whether the receipt can no longer change.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Only a pending receipt moves, and only to applied or rejected.
     */
    public function canMoveTo(self $to): bool
    {
        return $this === self::Pending && $to->isFinal();
    }

    /**
     * The status after the transition to $to.
     *
     * @throws InvalidReceiptTransition When canMoveTo($to) is false.
     */
    public function moveTo(self $to): self
    {
        if (! $this->canMoveTo($to)) {
            throw InvalidReceiptTransition::between($this, $to);
        }

        return $to;
    }
}
