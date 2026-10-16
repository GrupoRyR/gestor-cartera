<?php

namespace App\Modules\Cartera\Domain\Dashboard;

/**
 * How worrying the overdue share of a portfolio is: up to 15 % is low, up to
 * 30 % medium and above that high (the thresholds of the Phase 1 app).
 */
enum OverdueRisk: string
{
    case Low = 'bajo';

    case Medium = 'medio';

    case High = 'alto';

    private const float MEDIUM_ABOVE = 15.0;

    private const float HIGH_ABOVE = 30.0;

    /**
     * @param  float  $percent  Overdue share of the portfolio, 0 to 100.
     */
    public static function forPercent(float $percent): self
    {
        return match (true) {
            $percent > self::HIGH_ABOVE => self::High,
            $percent > self::MEDIUM_ABOVE => self::Medium,
            default => self::Low,
        };
    }

    /**
     * The tone of the <x-ui.stat> and <x-ui.chip> components.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Low => 'success',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }
}
