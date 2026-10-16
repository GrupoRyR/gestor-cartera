<?php

namespace App\Modules\Comisiones\Domain\Parameters;

/**
 * What a derived commission (81.1, 85.1; §5.5) is calculated on.
 */
enum DerivedBase: string
{
    /** The source code's total sales of the period. */
    case Sales = 'venta';

    /** The source code's collected base without VAT, flat (no tranches). */
    case Collections = 'recaudo';

    public function label(): string
    {
        return match ($this) {
            self::Sales => 'Venta',
            self::Collections => 'Recaudo',
        };
    }
}
