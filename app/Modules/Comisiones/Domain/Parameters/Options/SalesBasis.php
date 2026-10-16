<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.2: whether the SAP sales total already excludes VAT.
 */
enum SalesBasis: string
{
    /** The SAP "Total" is the commission base, as the Excel workbook does today. */
    case AsReported = 'reportado';

    /** The SAP "Total" includes VAT: the base is total / (1 + VAT rate), to cents. */
    case IncludesVat = 'con_iva';

    public function label(): string
    {
        return match ($this) {
            self::AsReported => 'Valor reportado por SAP',
            self::IncludesVat => 'Valor con IVA (se descuenta)',
        };
    }
}
