<?php

namespace App\Modules\Soportes\Domain;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * How the client says they paid. Optional on the receipt: the file itself
 * is the proof, the method only helps the analyst find the payment.
 */
enum PaymentMethod: string
{
    use HasOptions;

    case Transfer = 'transferencia';

    case Deposit = 'consignacion';

    case Pse = 'pse';

    case Other = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transferencia',
            self::Deposit => 'Consignación',
            self::Pse => 'PSE',
            self::Other => 'Otro',
        };
    }
}
