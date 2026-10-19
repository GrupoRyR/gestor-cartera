<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * A reimbursement or a deduction captured by hand with support (§4
 * "AjusteManual", §5.7). The amount is always positive; the kind gives the sign.
 */
final readonly class ManualAdjustment
{
    /**
     * @throws InvalidArgumentException when the amount is not positive or the description is blank.
     */
    public function __construct(
        public int $sellerCode,
        public AdjustmentKind $kind,
        public Money $amount,
        public string $description,
    ) {
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException("An adjustment of code {$sellerCode} must be positive, got {$amount->amount()}.");
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException("An adjustment of code {$sellerCode} needs a description.");
        }
    }
}
