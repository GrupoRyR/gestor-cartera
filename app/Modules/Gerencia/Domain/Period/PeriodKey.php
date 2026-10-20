<?php

namespace App\Modules\Gerencia\Domain\Period;

/**
 * The reporting periods of the management dashboard. The value is what the
 * `periodo` query parameter carries.
 */
enum PeriodKey: string
{
    /** From the first of this month to today. */
    case Month = 'mes';

    /** The whole previous calendar month. */
    case PreviousMonth = 'mes_anterior';

    /** From the first of the month two months ago to today. */
    case Quarter = 'trimestre';

    /** From January 1 to today. */
    case Year = 'anio';

    /**
     * The period asked for in the request; the current month when the value
     * is missing or unknown.
     */
    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Month;
    }
}
