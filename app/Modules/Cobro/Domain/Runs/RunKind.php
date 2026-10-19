<?php

namespace App\Modules\Cobro\Domain\Runs;

/**
 * The collection mails Cobro sends in batches. The value is also the
 * OutgoingMail category and the URL segment of its screens.
 */
enum RunKind: string
{
    // 4.1: overdue invoices, every Wednesday.
    case Weekly = 'cobro_semanal';

    // 4.2: the portfolio statement at month close.
    case Circularization = 'circularizacion';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Cobro semanal',
            self::Circularization => 'Circularización',
        };
    }

    /**
     * URL segment: cobro-semanal or circularizacion.
     */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value);
    }

    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(str_replace('-', '_', $slug));
    }
}
