<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use DateTimeImmutable;

/**
 * A payment or a crossing as the settlement commissioned it (§4
 * "LiquidacionDetalleRecaudo", §8.2): one row of the collection PDF.
 */
final readonly class CollectionDetail
{
    /**
     * @param  DateTimeImmutable  $paymentDate  The payment date, or the crossing date for a crossing.
     * @param  Money  $paidBase  The paid amount without VAT (§5.3, §5.4).
     * @param  int  $days  Payment date minus invoice date, never below zero (§5.3).
     * @param  bool  $tranched  Whether the share came from the tranche table (false for code 85 and 85.1).
     * @param  Percentage  $share  Share of the rate paid for the days (§5.3).
     * @param  Money  $commission  Exact: paid base × rate × share (rounded only in the code total).
     */
    public function __construct(
        public string $key,
        public CollectionSource $source,
        public int $sellerCode,
        public string $invoiceNumber,
        public string $clientCode,
        public string $clientName,
        public DateTimeImmutable $invoiceDate,
        public DateTimeImmutable $paymentDate,
        public Money $invoiceAmount,
        public Money $paidBase,
        public int $days,
        public bool $tranched,
        public Percentage $share,
        public Percentage $rate,
        public Money $commission,
    ) {}

    public function isCrossing(): bool
    {
        return $this->source === CollectionSource::Crossing;
    }

    /** The client as the PDF shows it: crossings carry " (cruce)" (§5.4). */
    public function clientLabel(): string
    {
        return $this->isCrossing() ? "{$this->clientName} (cruce)" : $this->clientName;
    }
}
