<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Collection status of a client (Part B, module 0N, item 4).
 *
 * The case order is the order of the Kanban columns; iterate cases() to
 * build them. Core owns the transition rules:
 *
 * - Automatic statuses (Current, DueSoon, Overdue, PaymentReceived) are
 *   recomputed from the latest receivables snapshot, using the grace days
 *   of the client's company (Company::$graceDays).
 * - Manual statuses (InProgress, Committed, Defaulted, Legal) are set by
 *   users or by the commitment workflow and survive new snapshots while the
 *   client still has a balance.
 */
enum ClientStatus: string
{
    use HasOptions;

    /** No overdue invoice and nothing due soon. */
    case Current = 'al_dia';

    /** No overdue invoice, but at least one invoice is about to fall due. */
    case DueSoon = 'por_vencer';

    /** At least one invoice is past its due date plus the company's grace days. */
    case Overdue = 'vencida';

    /** Someone is actively working on the collection. */
    case InProgress = 'en_gestion';

    /** The client has a pending payment commitment. */
    case Committed = 'compromiso';

    /** The client broke a payment commitment. */
    case Defaulted = 'incumplido';

    /** The latest snapshot shows no balance after having had one. */
    case PaymentReceived = 'pago_recibido';

    /** The account was handed over to legal collection. */
    case Legal = 'juridico';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Al día',
            self::DueSoon => 'Por vencer',
            self::Overdue => 'Vencida',
            self::InProgress => 'En gestión',
            self::Committed => 'Compromiso',
            self::Defaulted => 'Incumplido',
            self::PaymentReceived => 'Pago recibido',
            self::Legal => 'Jurídico',
        };
    }
}
