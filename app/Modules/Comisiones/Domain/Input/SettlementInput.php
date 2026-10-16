<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * Everything a settlement reads for one period: what the infrastructure maps
 * from the SAP staging tables and the manual captures (§4).
 *
 * The SAP lines may include days of other periods (the robot sends seven
 * extra days of the previous month); the engine ignores them silently
 * (§5.1). Budgets, OBR sales and adjustments are already those of the period.
 */
final readonly class SettlementInput
{
    /**
     * @param  list<SaleLine>  $sales
     * @param  list<PaymentLine>  $payments
     * @param  list<CrossingLine>  $crossings
     * @param  list<ClientAssignment>  $assignments  The whole assignment history, not just the period.
     * @param  list<Budget>  $budgets
     * @param  list<ObrSale>  $obrSales
     * @param  list<ManualAdjustment>  $adjustments
     */
    public function __construct(
        public Period $period,
        public array $sales = [],
        public array $payments = [],
        public array $crossings = [],
        public array $assignments = [],
        public array $budgets = [],
        public array $obrSales = [],
        public array $adjustments = [],
    ) {}
}
