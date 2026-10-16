<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * The monthly sales budget of a code, loaded by hand (§4 "Presupuesto").
 * Several rows of the same code add up; zero counts as no budget (§5.2).
 */
final readonly class Budget
{
    /**
     * @throws InvalidArgumentException when the amount is negative.
     */
    public function __construct(
        public int $sellerCode,
        public Money $amount,
    ) {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException("The budget of code {$sellerCode} cannot be negative, got {$amount->amount()}.");
        }
    }
}
