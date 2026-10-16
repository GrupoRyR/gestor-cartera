<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\ManualAdjustment;
use App\Modules\Comisiones\Domain\Input\ObrSale;
use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;

/**
 * The settlement of one seller code for the period (§5.7, §4
 * "LiquidacionConsolidado"), with the rule that was applied.
 *
 * total = sale + collection + OBR commissions + reimbursements − deductions
 * + director commission (the last one only when the director commission is
 * placed on its associated code, §14.4).
 */
final readonly class CodeSettlement
{
    public Money $total;

    /**
     * @param  Money|null  $budget  Sum of the code's budget rows; null when none was loaded.
     * @param  Percentage  $compliance  Sales over budget at scale 6, shown only; zero without budget (§5.2).
     * @param  bool  $meetsMinimum  Exact check of sales ≥ budget × minimum, or no minimum (§5.2).
     * @param  Money  $collected  Paid base of payments and crossings (§5.3, §5.4).
     * @param  list<SaleDetail>  $saleDetails
     * @param  list<CollectionDetail>  $collectionDetails  Sorted by payment date, then key (§8.2).
     * @param  list<ObrSale>  $obrSales
     * @param  list<ManualAdjustment>  $adjustments
     */
    public function __construct(
        public SellerCodeRule $rule,
        public Money $sales,
        public ?Money $budget,
        public Percentage $compliance,
        public bool $meetsMinimum,
        public Money $saleCommission,
        public Money $collected,
        public Money $collectionCommission,
        public Money $obrCommission,
        public Money $reimbursements,
        public Money $deductions,
        public Money $directorCommission,
        public array $saleDetails = [],
        public array $collectionDetails = [],
        public array $obrSales = [],
        public array $adjustments = [],
    ) {
        $this->total = $saleCommission
            ->plus($collectionCommission)
            ->plus($obrCommission)
            ->plus($reimbursements)
            ->minus($deductions)
            ->plus($directorCommission);
    }

    public function code(): int
    {
        return $this->rule->code;
    }

    /** Whether a budget above zero was loaded (§5.2). */
    public function hasBudget(): bool
    {
        return $this->budget !== null && $this->budget->isPositive();
    }

    /**
     * Whether the code has anything to show: lines, a budget, manual captures
     * or a director commission. Codes without activity still settle at zero.
     */
    public function hasActivity(): bool
    {
        return $this->saleDetails !== []
            || $this->collectionDetails !== []
            || $this->obrSales !== []
            || $this->adjustments !== []
            || $this->hasBudget()
            || ! $this->directorCommission->isZero();
    }

    /** Number of collection lines, payments and crossings (Annex 2: "10 transacciones"). */
    public function transactions(): int
    {
        return count($this->collectionDetails);
    }

    /** Whether the sale, the collection or the total commission is below zero (§9.6). */
    public function hasNegativeCommission(): bool
    {
        return $this->saleCommission->isNegative()
            || $this->collectionCommission->isNegative()
            || $this->total->isNegative();
    }
}
