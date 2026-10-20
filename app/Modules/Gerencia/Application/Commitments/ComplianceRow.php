<?php

namespace App\Modules\Gerencia\Application\Commitments;

use App\Modules\Gerencia\Domain\Commitments\AdvisorCompliance;

/**
 * The compliance of one advisor with the name SAP gives them; the view
 * words the case of an unknown advisor.
 */
final readonly class ComplianceRow
{
    /**
     * @param  string|null  $advisorName  Null when the commitment has no advisor or the
     *                                    advisor is not among the ones the user can see.
     */
    public function __construct(
        public AdvisorCompliance $compliance,
        public ?string $advisorName,
    ) {}
}
