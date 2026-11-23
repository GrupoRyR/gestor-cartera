<?php

namespace App\Modules\Soportes\Application\Upload;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\Invoice;

/**
 * The invoices a receipt may cover: the open invoices of the client that are
 * overdue (Invoice::$daysOverdue > 0) and still owe money (balance > 0, so
 * credit notes are left out). Core has no such query, so the module filters
 * CarteraRepository::openInvoices(), which already applies the user scope.
 */
final readonly class OverdueInvoices
{
    public function __construct(private CarteraRepository $cartera) {}

    /**
     * Oldest due date first; empty when the client is out of scope.
     *
     * @return list<Invoice>
     */
    public function forClient(int $clientId): array
    {
        return array_values(array_filter(
            $this->cartera->openInvoices($clientId),
            fn (Invoice $invoice): bool => $invoice->daysOverdue > 0 && $invoice->balance > 0,
        ));
    }

    /**
     * The same invoices grouped by document number, in the order each number
     * first appears: a document with split payment terms has one invoice per
     * overdue installment, and the receipt covers the document as a whole.
     *
     * @return list<non-empty-list<Invoice>>
     */
    public function byDocument(int $clientId): array
    {
        $documents = [];

        foreach ($this->forClient($clientId) as $invoice) {
            // Prefixed so a numeric document number never becomes an integer key.
            $documents['#'.$invoice->documentNumber][] = $invoice;
        }

        return array_values($documents);
    }
}
