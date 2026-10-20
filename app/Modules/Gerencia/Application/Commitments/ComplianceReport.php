<?php

namespace App\Modules\Gerencia\Application\Commitments;

use App\Modules\Gerencia\Domain\Commitments\ComplianceTally;

/**
 * Compliance with the payment commitments due in a period, by advisor.
 */
final readonly class ComplianceReport
{
    /**
     * @param  list<ComplianceRow>  $rows  Lowest rate first.
     * @param  bool  $truncated  The commitments were read up to the page cap and more
     *                           remain, so the figures cover only part of the period.
     */
    public function __construct(
        public array $rows,
        public bool $truncated,
    ) {}

    /**
     * Fulfilled share of everything evaluated, from 0 to 100; null when
     * nothing was evaluated.
     */
    public function overallRate(): ?float
    {
        return ComplianceTally::overallRate(array_map(fn (ComplianceRow $row) => $row->compliance, $this->rows));
    }
}
