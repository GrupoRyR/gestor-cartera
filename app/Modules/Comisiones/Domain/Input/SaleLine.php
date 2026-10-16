<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Dates;
use App\Modules\Comisiones\Domain\Shared\Money;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One line of a SAP sales document (Report 1), the base of the sales
 * commission (§5.2). It belongs to the period of its document date (§5.1).
 */
final readonly class SaleLine
{
    public DateTimeImmutable $documentDate;

    /**
     * @param  string  $key  Natural key: document number + position (Report 1).
     * @param  int|null  $sellerCode  Code read from the seller text; null when SAP gives none.
     * @param  string  $sellerText  The seller column as SAP reports it ("14 MONICA RIVERA AREVALO").
     * @param  Money  $total  SAP "Total", signed: credit notes and returns are negative.
     * @param  string  $exchangeRate  SAP "TRM" as a plain decimal.
     *
     * @throws InvalidArgumentException when the key or the currency is blank, or the
     *                                  exchange rate is not a positive plain decimal.
     */
    public function __construct(
        public string $key,
        public ?int $sellerCode,
        public string $sellerText,
        public string $documentNumber,
        DateTimeImmutable $documentDate,
        public DocumentType $documentType,
        public string $clientCode,
        public string $clientName,
        public Money $total,
        public string $currency = 'COP',
        public string $exchangeRate = '1',
        public bool $annulled = false,
    ) {
        LineFields::assertValid($key, $currency, $exchangeRate);

        $this->documentDate = Dates::day($documentDate);
    }

    /** The date that assigns the line to a period: the document date. */
    public function periodDate(): DateTimeImmutable
    {
        return $this->documentDate;
    }

    /** Whether the line is in another currency than pesos; its TRM never makes a peso line foreign (§14.7). */
    public function isForeignCurrency(): bool
    {
        return LineFields::isForeign($this->currency);
    }
}
