<?php

namespace App\Modules\Bancos\Application\Movements;

use App\Modules\Bancos\Domain\Movement\MovementStatus;
use Carbon\CarbonImmutable;

/**
 * What the list of movements of a bank is narrowed to, and in which order.
 */
final readonly class MovementFilter
{
    /** Sort key (as it appears in the URL) => column. */
    public const array SORTS = ['date' => 'movement_date', 'amount' => 'amount', 'status' => 'status'];

    /**
     * @param  CarbonImmutable|null  $from  First day, inclusive.
     * @param  CarbonImmutable|null  $to  Last day, inclusive.
     * @param  string|null  $minAmount  Decimal string, e.g. "1000000" or "1000000.50".
     * @param  string|null  $maxAmount  Decimal string.
     * @param  string|null  $text  Part of the description or the reference.
     * @param  string  $sort  A key of SORTS.
     * @param  string  $direction  asc or desc.
     */
    public function __construct(
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $minAmount = null,
        public ?string $maxAmount = null,
        public ?MovementStatus $status = null,
        public ?string $text = null,
        public string $sort = 'date',
        public string $direction = 'desc',
    ) {}

    /**
     * True when something narrows the list (the "clear filters" button shows).
     */
    public function isNarrowed(): bool
    {
        return $this->from !== null || $this->to !== null || $this->minAmount !== null
            || $this->maxAmount !== null || $this->status !== null || $this->text !== null;
    }
}
