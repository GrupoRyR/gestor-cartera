<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.7: lines reported in a foreign currency or with an exchange rate other than 1.
 */
enum CurrencyTreatment: string
{
    /** Keep the reported amount and warn, as the Excel workbook does today (it ignores the TRM). */
    case AsReported = 'reportado';

    /** Convert the amount with the line's exchange rate (TRM), to cents. */
    case Convert = 'convertir';

    public function label(): string
    {
        return match ($this) {
            self::AsReported => 'Valor reportado',
            self::Convert => 'Convertir con la TRM',
        };
    }
}
