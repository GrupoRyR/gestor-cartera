<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.5: which date picks the client's seller for a crossing (§5.4).
 */
enum CrossingSellerDate: string
{
    /** The seller assigned to the client on the crossing date. */
    case CrossingDate = 'fecha_cruce';

    /** The seller assigned to the client on the invoice date. */
    case InvoiceDate = 'fecha_factura';

    public function label(): string
    {
        return match ($this) {
            self::CrossingDate => 'Vendedor a la fecha del cruce',
            self::InvoiceDate => 'Vendedor a la fecha de la factura',
        };
    }
}
