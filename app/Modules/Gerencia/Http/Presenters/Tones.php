<?php

namespace App\Modules\Gerencia\Http\Presenters;

/**
 * The status tone (neutral, success, warning or danger) of a dashboard
 * figure. The thresholds are the management's reading of the numbers, not
 * a setting.
 */
final class Tones
{
    /** Overdue share of the portfolio (percent) from which the situation is serious. */
    public const float OVERDUE_DANGER = 30.0;

    /** Overdue share of the portfolio (percent) from which it deserves attention. */
    public const float OVERDUE_WARNING = 15.0;

    /** Commitment compliance (percent) from which it is good. */
    public const float COMPLIANCE_SUCCESS = 90.0;

    /** Commitment compliance (percent) from which it is acceptable. */
    public const float COMPLIANCE_WARNING = 70.0;

    /**
     * Tone of the overdue share of the portfolio, from 0 to 100.
     */
    public static function overdue(float $percent): string
    {
        return match (true) {
            $percent >= self::OVERDUE_DANGER => 'danger',
            $percent >= self::OVERDUE_WARNING => 'warning',
            default => 'success',
        };
    }

    /**
     * Tone of a compliance rate, from 0 to 100; neutral when there is none.
     */
    public static function compliance(?float $percent): string
    {
        return match (true) {
            $percent === null => 'neutral',
            $percent >= self::COMPLIANCE_SUCCESS => 'success',
            $percent >= self::COMPLIANCE_WARNING => 'warning',
            default => 'danger',
        };
    }
}
