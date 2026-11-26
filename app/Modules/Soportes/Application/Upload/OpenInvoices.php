<?php

namespace App\Modules\Soportes\Application\Upload;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\Invoice;

/**
 * The invoices a receipt may cover: the open invoices of the client that
 * still owe money (balance > 0, so credit notes are left out), overdue or
 * not (user decision 2026-11-26). CarteraRepository::openInvoices() already
 * applies the user scope.
 */
final readonly class OpenInvoices
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
            fn (Invoice $invoice): bool => $invoice->balance > 0,
        ));
    }

    /**
     * The same invoices grouped by document number, in the order each number
     * first appears: a document with split payment terms has one invoice per
     * open installment, and the receipt covers the document as a whole.
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
