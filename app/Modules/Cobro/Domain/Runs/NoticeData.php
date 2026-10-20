<?php

namespace App\Modules\Cobro\Domain\Runs;

use App\Modules\Core\Contracts\Data\Invoice;
use Carbon\CarbonImmutable;

/**
 * What one client's collection mail shows: the client and the invoices of
 * the mail (every open invoice, with the overdue ones marked), with their
 * totals.
 */
final readonly class NoticeData
{
    /**
     * @param  list<Invoice>  $invoices  Oldest due date first.
     * @param  CarbonImmutable|null  $cutoffDate  Cutoff of the cartera snapshot the invoices come from.
     * @param  string  $clientCode  SAP client code (OCRD.CardCode).
     * @param  string|null  $group  SAP business partner group: the business unit (unidad de negocio).
     * @param  string|null  $paymentTerms  Payment terms as named in SAP, e.g. "30 DIAS".
     * @param  array<int, string>  $advisorNames  Advisor code => name, for the advisor of each invoice.
     * @param  int|null  $advisorCode  The client's advisor, for invoices without one.
     */
    public function __construct(
        public string $clientName,
        public string $taxId,
        public array $invoices,
        public ?CarbonImmutable $cutoffDate,
        public string $clientCode = '',
        public ?string $group = null,
        public ?string $paymentTerms = null,
        public array $advisorNames = [],
        public ?int $advisorCode = null,
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
        return array_sum(array_map(fn (Invoice $invoice): float => $invoice->balance, $this->overdueInvoices()));
    }

    /**
     * Number of invoices past due.
     */
    public function overdueCount(): int
    {
        return count($this->overdueInvoices());
    }

    /**
     * The invoices past due, in invoice order.
     *
     * @return list<Invoice>
     */
    public function overdueInvoices(): array
    {
        return array_values(array_filter($this->invoices, fn (Invoice $invoice): bool => $invoice->daysOverdue > 0));
    }

    /**
     * "Code NAME" of the invoice's advisor (or the client's), as SAP shows
     * it, e.g. "41 JENNY ROCIO BUITRAGO IBAÑEZ"; the code alone when the
     * name is unknown, empty without an advisor.
     */
    public function advisorLabel(Invoice $invoice): string
    {
        $code = $invoice->advisorCode ?? $this->advisorCode;

        if ($code === null) {
            return '';
        }

        $name = $this->advisorNames[$code] ?? null;

        return $name === null ? (string) $code : "{$code} {$name}";
    }
}
