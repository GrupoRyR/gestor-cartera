<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.2: how credit notes and returns affect sales.
 */
enum CreditNoteTreatment: string
{
    /** Signed lines net the sales of the period they belong to, as the Excel workbook does today (§5.2). */
    case Net = 'netear';

    /** Credit notes and returns are ignored. */
    case Exclude = 'excluir';

    public function label(): string
    {
        return match ($this) {
            self::Net => 'Netear notas crédito',
            self::Exclude => 'Excluir notas crédito',
        };
    }
}
