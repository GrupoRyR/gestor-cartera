<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * An open receivable document (factura) in a receivables snapshot.
 *
 * Amounts are Colombian pesos, for display and aggregation only. Dates are
 * date-only values at the start of the day, America/Bogota.
 */
final readonly class Invoice
{
    /**
     * @param  int  $id  Platform id of the snapshot row.
     * @param  int  $clientId  Platform id of the client (ClientSummary::$id).
     * @param  string  $documentNumber  SAP document number (cartera "documento").
     * @param  string  $documentType  SAP document type (cartera "tipo_doc"), e.g. invoice or credit note.
     * @param  CarbonImmutable  $issuedAt  Document date.
     * @param  CarbonImmutable  $dueAt  Due date (of the installment, for split payment terms).
     * @param  float  $amount  Original amount of the document.
     * @param  float  $balance  Outstanding amount at the snapshot cutoff; negative for unapplied credits.
     * @param  int  $daysOverdue  Days from $dueAt to the snapshot cutoff date: positive = overdue,
     *                            0 = due on the cutoff date, negative = not due yet. Grace days do
     *                            not change it.
     * @param  int|null  $advisorCode  Advisor of the document (OSLP.SlpCode).
     */
    public function __construct(
        public int $id,
        public CompanyCode $company,
        public int $clientId,
        public string $documentNumber,
        public string $documentType,
        public CarbonImmutable $issuedAt,
        public CarbonImmutable $dueAt,
        public float $amount,
        public float $balance,
        public int $daysOverdue,
        public ?int $advisorCode = null,
    ) {}
}
