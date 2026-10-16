<?php

namespace App\Modules\Comisiones\Domain\Calculation;

/**
 * Whether a collection line is a payment (§5.3) or a crossing (§5.4).
 */
enum CollectionSource: string
{
    case Payment = 'pago';
    case Crossing = 'cruce';

    public function label(): string
    {
        return match ($this) {
            self::Payment => 'Pago',
            self::Crossing => 'Cruce',
        };
    }
}
