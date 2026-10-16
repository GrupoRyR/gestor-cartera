<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\DocumentType;
use App\Modules\Comisiones\Domain\Shared\Money;
use DateTimeImmutable;

/**
 * A sales line as the settlement counted it (§4 "LiquidacionDetalleVenta").
 */
final readonly class SaleDetail
{
    /**
     * @param  Money  $total  The SAP total as reported, signed.
     * @param  Money  $amount  What counts as sales: the total converted to pesos and
     *                         without VAT when the options say so (§14.2, §14.7).
     */
    public function __construct(
        public string $key,
        public int $sellerCode,
        public string $documentNumber,
        public DateTimeImmutable $documentDate,
        public DocumentType $documentType,
        public string $clientCode,
        public string $clientName,
        public Money $total,
        public Money $amount,
    ) {}
}
