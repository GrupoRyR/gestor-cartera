<?php

namespace App\Modules\Comisiones\Domain\Input;

/**
 * The class of a SAP sales document (Report 1). Credit notes and returns
 * come with a negative total and reduce the sales of their period (§5.2).
 */
enum DocumentType: string
{
    case Invoice = 'factura';
    case CreditNote = 'nota_credito';
    case DebitNote = 'nota_debito';
    case Return = 'devolucion';

    /** Whether the document reduces sales (open point 14.2 can exclude these). */
    public function isCredit(): bool
    {
        return $this === self::CreditNote || $this === self::Return;
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura',
            self::CreditNote => 'Nota crédito',
            self::DebitNote => 'Nota débito',
            self::Return => 'Devolución',
        };
    }
}
