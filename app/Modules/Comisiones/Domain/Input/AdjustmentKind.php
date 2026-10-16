<?php

namespace App\Modules\Comisiones\Domain\Input;

/**
 * Whether a manual adjustment adds to or subtracts from the code total (§5.7).
 */
enum AdjustmentKind: string
{
    case Reimbursement = 'reintegro';
    case Deduction = 'descuento';

    public function label(): string
    {
        return match ($this) {
            self::Reimbursement => 'Reintegro',
            self::Deduction => 'Descuento',
        };
    }
}
