<?php

namespace App\Modules\Comisiones\Domain\Parameters;

/**
 * Where a seller code is settled (Annex 1, "Consolidado").
 */
enum CodeScope: string
{
    /** Section 1 of the consolidated report: the regular sellers. */
    case Regular = 'regular';

    /** Section 2: channel directors paid apart from the regular consolidation (81, 84, 90; §5.5). */
    case Separate = 'aparte';

    /** Never earns a commission; loaded only to reconcile sales with SAP (80, 100; §5.3). */
    case Control = 'control';

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Regular',
            self::Separate => 'Aparte',
            self::Control => 'Control',
        };
    }
}
