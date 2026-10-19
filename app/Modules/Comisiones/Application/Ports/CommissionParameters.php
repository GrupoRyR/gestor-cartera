<?php

namespace App\Modules\Comisiones\Application\Ports;

use App\Modules\Comisiones\Domain\Parameters\ParameterBook;

/**
 * Every version of the commission parameters, as the parameter screen keeps
 * them (§7.2). Implemented by the infrastructure; DefaultParameters holds
 * the values to seed it with.
 */
interface CommissionParameters
{
    public function book(): ParameterBook;
}
