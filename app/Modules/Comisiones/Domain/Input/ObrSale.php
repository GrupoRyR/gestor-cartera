<?php

namespace App\Modules\Comisiones\Domain\Input;

use App\Modules\Comisiones\Domain\Shared\Money;
use App\Modules\Comisiones\Domain\Shared\Percentage;

/**
 * A manual OBR/Escudos sale with its own rate (§5.5), captured with support.
 */
final readonly class ObrSale
{
    public function __construct(
        public int $sellerCode,
        public string $description,
        public Money $soldValue,
        public Percentage $rate,
    ) {}
}
