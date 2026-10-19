<?php

namespace App\Modules\Comisiones\Domain\Parameters\Options;

/**
 * Open point 14.6: whether sales compliance is measured per code or per person (§5.2).
 */
enum ComplianceScope: string
{
    /** Each code against its own budget, as the Excel workbook does today. */
    case Code = 'codigo';

    /** The sales of all the person's budgeted codes against the sum of their budgets. */
    case Person = 'persona';

    public function label(): string
    {
        return match ($this) {
            self::Code => 'Por código',
            self::Person => 'Por persona',
        };
    }
}
