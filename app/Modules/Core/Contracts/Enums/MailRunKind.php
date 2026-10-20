<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * The batches of collection mail Cobro sends (Part B, "Módulo 4"), as read
 * through MailRunResultsProvider.
 *
 * The value is the one Cobro stores for the run and the OutgoingMail
 * category of its mails.
 */
enum MailRunKind: string
{
    use HasOptions;

    /** 4.1: the weekly overdue-invoice notice. */
    case WeeklyCollection = 'cobro_semanal';

    /** 4.2: the portfolio statement at month close. */
    case Circularization = 'circularizacion';

    public function label(): string
    {
        return match ($this) {
            self::WeeklyCollection => 'Cobro semanal',
            self::Circularization => 'Circularización',
        };
    }
}
