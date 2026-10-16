<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * One member code of a channel team, with what it adds to the director base (§5.6).
 */
final readonly class DirectorTeamRow
{
    public function __construct(
        public int $code,
        public string $name,
        public Money $sales,
        public Money $collected,
    ) {}
}
