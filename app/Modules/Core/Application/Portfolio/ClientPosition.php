<?php

namespace App\Modules\Core\Application\Portfolio;

use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Domain\Status\OpenItem;

/**
 * What one client owes in a snapshot, built item by item: its open items for
 * ClientStatusRules and the figures cached on the client. A client without
 * items owes nothing.
 */
final class ClientPosition
{
    /** @var list<OpenItem> */
    private array $items = [];

    private int $balance = 0;

    private int $overdue = 0;

    private ?int $maxDaysOverdue = null;

    public function add(int $balanceCents, int $daysOverdue): void
    {
        $item = new OpenItem(Cents::toFloat($balanceCents), $daysOverdue);
        $this->items[] = $item;
        $this->balance += $balanceCents;

        if ($daysOverdue > 0 && $balanceCents > 0) {
            $this->overdue += $balanceCents;
        }

        if ($item->balance > ClientStatusRules::BALANCE_TOLERANCE) {
            $this->maxDaysOverdue = max($this->maxDaysOverdue ?? $daysOverdue, $daysOverdue);
        }
    }

    /**
     * @return list<OpenItem>
     */
    public function items(): array
    {
        return $this->items;
    }

    /**
     * Total balance in cents, credit notes included.
     */
    public function balance(): int
    {
        return $this->balance;
    }

    /**
     * Cents owed on items past due (positive balance, positive days overdue).
     */
    public function overdue(): int
    {
        return $this->overdue;
    }

    /**
     * Most days overdue of an item that still owes more than the tolerance;
     * negative when none is past due yet, 0 when none owes.
     */
    public function maxDaysOverdue(): int
    {
        return $this->maxDaysOverdue ?? 0;
    }
}
