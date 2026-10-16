<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\PersonRef;

/**
 * What one person is paid for the period: the sum of every role, the
 * "valor pago nómina" reported to payroll (§5.7).
 */
final readonly class PersonSettlement
{
    /**
     * @param  list<PersonRole>  $roles
     */
    public function __construct(
        public PersonRef $person,
        public array $roles,
        public Money $total,
    ) {}
}
