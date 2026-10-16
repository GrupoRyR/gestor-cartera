<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * The sales of a period: the figures of every code with a rule, and the
 * loaded and unassigned totals for the §9.1 control.
 */
final readonly class SalesOutcome
{
    /**
     * @param  array<int, SalesFigures>  $codes  Keyed by code; every code with a rule in the period.
     * @param  Money  $loaded  Every kept line of the period, in its final amount.
     * @param  Money  $unassigned  Lines without a known code.
     */
    public function __construct(
        private array $codes,
        public Money $loaded,
        public Money $unassigned,
    ) {}

    /**
     * @throws InvalidArgumentException when the code has no rule in the period.
     */
    public function forCode(int $code): SalesFigures
    {
        return $this->codes[$code] ?? throw new InvalidArgumentException("Code {$code} has no sales figures.");
    }

    /** Sum of the sales of every code, control codes included (§9.1). */
    public function byCode(): Money
    {
        return Money::sum(array_map(fn (SalesFigures $figures) => $figures->sales, $this->codes));
    }
}
