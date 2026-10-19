<?php

namespace App\Modules\Bancos\Domain\Movement;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Where a credit stands with the portfolio analyst: waiting to be applied in
 * SAP, applied (with its cash receipt number) or ignored (not a customer
 * payment, e.g. a transfer between own accounts).
 */
enum MovementStatus: string
{
    use HasOptions;

    case Pending = 'pendiente';

    case Applied = 'aplicado';

    case Ignored = 'ignorado';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Applied => 'Aplicado',
            self::Ignored => 'Ignorado',
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
            self::Ignored => 'neutral',
        };
    }
}
