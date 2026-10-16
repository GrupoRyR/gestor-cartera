<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.4: where the channel director commission is shown (§5.6).
 */
enum DirectorPlacement: string
{
    /** Its own block in section 3 of the consolidated report, as the Excel workbook does today. */
    case Separate = 'aparte';

    /** Added to the total of the director's associated code (53 or 50). */
    case AssociatedCode = 'codigo_asociado';

    public function label(): string
    {
        return match ($this) {
            self::Separate => 'Bloque aparte',
            self::AssociatedCode => 'En el código asociado',
        };
    }
}
