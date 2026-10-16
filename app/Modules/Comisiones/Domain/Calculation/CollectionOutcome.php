<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;
use InvalidArgumentException;

/**
 * The collections of a period: the figures of every code with a rule, and the
 * loaded and unassigned totals for the §9.2 control.
 */
final readonly class CollectionOutcome
{
    /**
     * @param  array<int, CollectionFigures>  $codes  Keyed by code; every code with a rule in the period.
     * @param  Money  $loaded  Paid base of every kept payment and crossing of the period, assigned or not.
     * @param  Money  $unassigned  Payments without a known code and crossings without a known seller.
     */
    public function __construct(
        private array $codes,
        public Money $loaded,
        public Money $unassigned,
    ) {}

    /**
     * @throws InvalidArgumentException when the code has no rule in the period.
     */
    public function forCode(int $code): CollectionFigures
    {
        return $this->codes[$code] ?? throw new InvalidArgumentException("Code {$code} has no collection figures.");
    }

    /** Sum of the collected amount of every code, control codes included (§9.2). */
    public function settled(): Money
    {
        return Money::sum(array_map(fn (CollectionFigures $figures) => $figures->collected, $this->codes));
    }
}
