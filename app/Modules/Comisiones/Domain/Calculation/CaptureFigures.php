<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Input\ManualAdjustment;
use App\Modules\Comisiones\Domain\Input\ObrSale;
use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * The manual part of a code settlement (§5.5, §5.7), as ManualCaptures
 * computes it.
 */
final readonly class CaptureFigures
{
    /**
     * @param  Money  $obrCommission  Σ sold value × rate of the OBR/Escudos sales, rounded once to the peso.
     * @param  Money  $reimbursements  Σ reimbursements; they add to the code total.
     * @param  Money  $deductions  Σ deductions; they subtract from the code total.
     * @param  list<ObrSale>  $obrSales
     * @param  list<ManualAdjustment>  $adjustments
     */
    public function __construct(
        public Money $obrCommission,
        public Money $reimbursements,
        public Money $deductions,
        public array $obrSales,
        public array $adjustments,
    ) {}
}
