<?php

namespace App\Modules\Core\Domain\Status;

use App\Modules\Core\Contracts\Enums\ClientStatus;

/**
 * Computes a client's status from its open items in a receivables snapshot.
 *
 * Automatic statuses are recomputed with every snapshot; manual statuses are
 * set by users or by the commitment workflow and survive new snapshots while
 * the client still owes money (see app/Modules/Core/README.md).
 */
final class ClientStatusRules
{
    /** Balances at or below this amount (in pesos) count as zero. */
    public const BALANCE_TOLERANCE = 0.01;

    /** @var list<ClientStatus> */
    public const MANUAL = [
        ClientStatus::InProgress,
        ClientStatus::Committed,
        ClientStatus::Defaulted,
        ClientStatus::Legal,
    ];

    /** @var list<ClientStatus> */
    public const AUTOMATIC = [
        ClientStatus::Current,
        ClientStatus::DueSoon,
        ClientStatus::Overdue,
        ClientStatus::PaymentReceived,
    ];

    public static function isManual(ClientStatus $status): bool
    {
        return in_array($status, self::MANUAL, true);
    }

    public static function isAutomatic(ClientStatus $status): bool
    {
        return in_array($status, self::AUTOMATIC, true);
    }

    /**
     * The client's status after a new snapshot, evaluated in this order:
     *
     * 1. Total balance (credit notes included) at or below the tolerance:
     *    Current stays Current, any other status, manual ones included,
     *    becomes PaymentReceived.
     * 2. A manual status stays as it is.
     * 3. Overdue when an item that still owes money is more than the grace
     *    days past due.
     * 4. DueSoon when such an item is due within the due soon window, today,
     *    or past due but still inside the grace days.
     * 5. Current otherwise.
     *
     * @param  list<OpenItem>  $items  The client's open items; an empty list is a zero balance.
     */
    public static function resolve(ClientStatus $current, array $items, StatusParameters $params): ClientStatus
    {
        $total = array_sum(array_map(fn (OpenItem $item): float => $item->balance, $items));

        if ($total <= self::BALANCE_TOLERANCE) {
            return $current === ClientStatus::Current ? ClientStatus::Current : ClientStatus::PaymentReceived;
        }

        if (self::isManual($current)) {
            return $current;
        }

        $owed = array_filter($items, fn (OpenItem $item): bool => self::owes($item));

        foreach ($owed as $item) {
            if (self::overdueForStatus($item, $params)) {
                return ClientStatus::Overdue;
            }
        }

        foreach ($owed as $item) {
            if ($item->daysOverdue >= -$params->dueSoonDays) {
                return ClientStatus::DueSoon;
            }
        }

        return ClientStatus::Current;
    }

    /**
     * Whether the item makes the client Overdue: it still owes money and is
     * more than the grace days past due (with 15 grace days, 15 days past due
     * is not overdue yet and 16 is).
     */
    public static function overdueForStatus(OpenItem $item, StatusParameters $params): bool
    {
        return self::owes($item) && $item->daysOverdue > $params->graceDays;
    }

    private static function owes(OpenItem $item): bool
    {
        return $item->balance > self::BALANCE_TOLERANCE;
    }
}
