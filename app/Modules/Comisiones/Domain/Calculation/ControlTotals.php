<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The reconciliation controls of §9.1 and §9.2. "Loaded" is every line of
 * the period the settlement kept (after duplicates, annulments and options);
 * a line without a known code makes the totals differ.
 */
final readonly class ControlTotals
{
    /**
     * @param  Money  $salesByCode  Sum of the sales of every code, control codes 80 and 100 included.
     * @param  Money  $unassignedSales  Sales of lines without a known code.
     * @param  Money  $settledCollections  Sum of the collected amount of every code (payments and crossings).
     * @param  Money  $unassignedCollections  Collections of lines without a known code or seller.
     */
    public function __construct(
        public Money $loadedSales,
        public Money $salesByCode,
        public Money $unassignedSales,
        public Money $loadedCollections,
        public Money $settledCollections,
        public Money $unassignedCollections,
    ) {}

    /** §9.1: loaded sales = sales by code. */
    public function salesReconcile(): bool
    {
        return $this->loadedSales->equals($this->salesByCode);
    }

    /** §9.2: loaded collections = settled collections (payments and crossings). */
    public function collectionsReconcile(): bool
    {
        return $this->loadedCollections->equals($this->settledCollections);
    }
}
