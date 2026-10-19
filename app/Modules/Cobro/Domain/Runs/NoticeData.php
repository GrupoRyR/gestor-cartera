<?php

namespace App\Modules\Cobro\Domain\Runs;

use App\Modules\Core\Contracts\Data\Invoice;
use Carbon\CarbonImmutable;

/**
 * What one client's collection mail shows: the client and the invoices of
 * the mail (overdue ones for the weekly notice, every open one for the
 * circularization), with their totals.
 */
final readonly class NoticeData
{
    /**
     * @param  list<Invoice>  $invoices  Oldest due date first.
     * @param  CarbonImmutable|null  $cutoffDate  Cutoff of the cartera snapshot the invoices come from.
     */
    public function __construct(
        public string $clientName,
        public string $taxId,
        public array $invoices,
        public ?CarbonImmutable $cutoffDate,
    ) {}

    /**
     * Sum of the invoice balances.
     */
    public function total(): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->balance, $this->invoices));
    }

    /**
     * Sum of the balances of the invoices past due (daysOverdue > 0).
     */
    public function overdue(): float
    {
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->daysOverdue > 0 ? $invoice->balance : 0.0, $this->invoices));
    }

    /**
     * Number of invoices past due.
     */
    public function overdueCount(): int
    {
        return count(array_filter($this->invoices, fn (Invoice $invoice): bool => $invoice->daysOverdue > 0));
    }
}
