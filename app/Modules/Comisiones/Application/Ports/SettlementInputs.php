<?php

namespace App\Modules\Comisiones\Application\Ports;

use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * What a settlement reads for a period: the SAP lines (reports 1, 2 and 4),
 * the client assignment history (report 5) and the manual captures (budget,
 * OBR/Escudos, reimbursements and deductions). Implemented by the
 * infrastructure from its staging tables; see Application/README.md.
 */
interface SettlementInputs
{
    /**
     * Every line and capture the period may use. Lines dated outside the
     * period may come along (the robot sends 7 extra days of the previous
     * month); the engine ignores them.
     */
    public function forPeriod(Period $period): SettlementInput;
}
