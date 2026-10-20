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
     * URL segment and route-name segment of its screens: /cobro/semanal
     * (cobro.semanal.*) or /cobro/circularizacion (cobro.circularizacion.*).
     */
    public function segment(): string
    {
        return match ($this) {
            self::Weekly => 'semanal',
            self::Circularization => 'circularizacion',
        };
    }

    /**
     * Name of one of its routes, e.g. route('show') = cobro.semanal.show.
     */
    public function route(string $action): string
    {
        return "cobro.{$this->segment()}.{$action}";
    }
}
