<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Domain\Calculation\CollectionDetail;
use App\Modules\Comisiones\Domain\Calculation\SaleDetail;
use DateTimeImmutable;

/**
 * The filters of the settlement of a code (§7.5): client (code or name),
 * invoice number and a date range (document date of a sale, payment or
 * crossing date of a collection line). They narrow the lines shown; the
 * totals of the code stay those of the settlement.
 */
final readonly class LineFilters
{
    /**
     * @param  string|null  $from  "Y-m-d", inclusive.
     * @param  string|null  $to  "Y-m-d", inclusive.
     */
    public function __construct(
        public ?string $client = null,
        public ?string $invoice = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function isActive(): bool
    {
        return $this->client !== null || $this->invoice !== null || $this->from !== null || $this->to !== null;
    }

    public function sale(SaleDetail $line): bool
    {
        return $this->matches($line->clientCode, $line->clientName, $line->documentNumber, $line->documentDate);
    }

    public function collection(CollectionDetail $line): bool
    {
        return $this->matches($line->clientCode, $line->clientName, $line->invoiceNumber, $line->paymentDate);
    }

    private function matches(string $clientCode, string $clientName, string $invoice, DateTimeImmutable $date): bool
    {
        if ($this->client !== null && ! self::contains($clientCode, $this->client) && ! self::contains($clientName, $this->client)) {
            return false;
        }

        if ($this->invoice !== null && ! self::contains($invoice, $this->invoice)) {
            return false;
        }

        $day = $date->format('Y-m-d');

        return ($this->from === null || $day >= $this->from) && ($this->to === null || $day <= $this->to);
    }

    private static function contains(string $haystack, string $needle): bool
    {
        return str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }
}
