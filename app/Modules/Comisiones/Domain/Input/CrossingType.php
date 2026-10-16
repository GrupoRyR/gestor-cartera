<?php

namespace App\Modules\Comisiones\Domain\Input;

/**
 * What settled the invoice in a crossing (Report 4, §5.4).
 */
enum CrossingType: string
{
    case Advance = 'anticipo';
    case ClientSupplier = 'cliente_proveedor';
    case Other = 'otro';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Anticipo',
            self::ClientSupplier => 'Compensación cliente-proveedor',
            self::Other => 'Otro',
        };
    }
}
