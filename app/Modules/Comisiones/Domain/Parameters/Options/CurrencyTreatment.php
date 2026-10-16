<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.7: lines reported in a foreign currency. A line in pesos
 * (COP) is never converted under either option; a TRM other than 1 on it is
 * ignored with a warning.
 */
enum CurrencyTreatment: string
{
    /** Keep the reported amount and warn, as the Excel workbook does today (it ignores the TRM). */
    case AsReported = 'reportado';

    /** Convert the amount of a line in another currency with its exchange rate (TRM), to cents. */
    case Convert = 'convertir';

    public function label(): string
    {
        return match ($this) {
            self::AsReported => 'Valor reportado',
            self::Convert => 'Convertir con la TRM',
        };
    }
}
