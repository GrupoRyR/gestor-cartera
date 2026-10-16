<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Domain\Shared\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One payment applied to an invoice (Report 2), the base of the collection
 * commission (§5.3). It belongs to the period of its payment date (§5.1).
 */
final readonly class PaymentLine
{
    public DateTimeImmutable $paymentDate;

    public DateTimeImmutable $invoiceDate;

    /**
     * @param  string  $key  Natural key: payment + invoice + line (Report 2).
     * @param  int|null  $sellerCode  Code read from the seller text; null when SAP gives none.
     * @param  Money  $paidBase  SAP "Vlr Total Pagado", already without VAT (§5.3).
     * @param  string  $exchangeRate  SAP "TRM" as a plain decimal.
     *
     * @throws InvalidArgumentException when the key or the currency is blank, or the
     *                                  exchange rate is not a positive plain decimal.
     */
    public function __construct(
        public string $key,
        public ?int $sellerCode,
        public string $sellerText,
        public string $paymentNumber,
        DateTimeImmutable $paymentDate,
        public string $invoiceNumber,
        DateTimeImmutable $invoiceDate,
        public string $clientCode,
        public string $clientName,
        public Money $invoiceAmount,
        public Money $paidBase,
        public string $currency = 'COP',
        public string $exchangeRate = '1',
        public bool $annulled = false,
    ) {
        LineFields::assertValid($key, $currency, $exchangeRate);

        $this->paymentDate = Dates::day($paymentDate);
        $this->invoiceDate = Dates::day($invoiceDate);
    }

    /** The date that assigns the line to a period: the payment date. */
    public function periodDate(): DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function isForeignCurrency(): bool
    {
        return LineFields::isForeign($this->currency, $this->exchangeRate);
    }
}
