<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Modules\Comisiones\Domain\Parameters\SellerCodeRule;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;

/**
 * One code of the budget screen (§7.3): its rule in force, its budget of
 * the period when loaded, and the channel to suggest when it has none
 * (the seller master's).
 */
final readonly class BudgetRow
{
    public function __construct(
        public SellerCodeRule $rule,
        public ?BudgetRecord $budget,
        public ?string $suggestedChannel,
    ) {}

    /**
     * A code with a minimum compliance and no budget raises «Sin
     * presupuesto» when the period is calculated (§9).
     */
    public function needsBudget(): bool
    {
        return $this->budget === null && ! $this->rule->minimumCompliance->isZero();
    }

    public function channel(): ?string
    {
        return $this->budget === null ? $this->suggestedChannel : $this->budget->canal;
    }
}
