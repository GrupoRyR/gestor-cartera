<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Domain\Shared\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * An advance or a client-supplier compensation applied to an invoice
 * (Report 4, §5.4). It is collected like a payment, on the crossed amount
 * without VAT, and belongs to the period of its crossing date (§5.1).
 */
final readonly class CrossingLine
{
    public DateTimeImmutable $crossingDate;

    public DateTimeImmutable $invoiceDate;

    /**
     * @param  string  $key  Natural key of the crossing (Report 4).
     * @param  Money  $crossedAmount  Amount applied to the invoice, VAT included.
     * @param  Money|null  $invoiceTaxBase  Invoice base without VAT, when SAP gives it.
     * @param  Money|null  $invoiceVat  Invoice VAT, when SAP gives it.
     * @param  Money|null  $crossedAmountWithoutVat  Crossed amount without VAT, when known directly.
     * @param  int|null  $sellerOverride  Seller set by a user with support (§5.4); wins over the client assignment.
     * @param  string  $exchangeRate  SAP "TRM" as a plain decimal.
     *
     * @throws InvalidArgumentException when the key or the currency is blank, or the
     *                                  exchange rate is not a positive plain decimal.
     */
    public function __construct(
        public string $key,
        public CrossingType $type,
        public CrossingSource $source,
        public string $documentNumber,
        DateTimeImmutable $crossingDate,
        public string $invoiceNumber,
        DateTimeImmutable $invoiceDate,
        public string $clientCode,
        public string $clientName,
        public string $counterparty,
        public Money $invoiceAmount,
        public Money $crossedAmount,
        public ?Money $invoiceTaxBase = null,
        public ?Money $invoiceVat = null,
        public ?Money $crossedAmountWithoutVat = null,
        public ?int $sellerOverride = null,
        public string $currency = 'COP',
        public string $exchangeRate = '1',
        public bool $annulled = false,
    ) {
        LineFields::assertValid($key, $currency, $exchangeRate);

        $this->crossingDate = Dates::day($crossingDate);
        $this->invoiceDate = Dates::day($invoiceDate);
    }

    /** The date that assigns the line to a period: the crossing date. */
    public function periodDate(): DateTimeImmutable
    {
        return $this->crossingDate;
    }

    public function isForeignCurrency(): bool
    {
        return LineFields::isForeign($this->currency, $this->exchangeRate);
    }
}
