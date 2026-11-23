<?php

namespace App\Modules\Gerencia\Domain\Commitments;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * How one advisor of one company kept the payment commitments due in a
 * period. Cancelled commitments and those still to come are counted apart
 * and never evaluated.
 */
final readonly class AdvisorCompliance
{
    /**
     * @param  int|null  $advisorCode  Null for commitments without an advisor.
     * @param  int  $fulfilled  Paid, on time or late.
     * @param  int  $fulfilledOnTime  Of those, paid on or before the due date.
     * @param  int  $broken  Marked as broken (due and unpaid).
     * @param  int  $partiallyPaid  Closed with part of the amount paid (abonado): evaluated, not fulfilled.
     * @param  int  $overduePending  Still pending although the due date passed.
     * @param  int  $upcoming  Pending and due today or later.
     * @param  int  $cancelled  Cancelled.
     * @param  float  $amountEvaluated  Amount of the evaluated commitments.
     * @param  float  $amountFulfilled  Amount of the fulfilled commitments.
     */
    public function __construct(
        public CompanyCode $company,
        public ?int $advisorCode,
        public int $fulfilled,
        public int $fulfilledOnTime,
        public int $broken,
        public int $partiallyPaid,
        public int $overduePending,
        public int $upcoming,
        public int $cancelled,
        public float $amountEvaluated,
        public float $amountFulfilled,
    ) {}

    /**
     * Commitments that count towards the rate: fulfilled, broken, overdue
     * pending and partially paid.
     */
    public function evaluated(): int
    {
        return $this->fulfilled + $this->broken + $this->overduePending + $this->partiallyPaid;
    }

    /**
     * Share of the evaluated commitments that were fulfilled, from 0 to 100;
     * null when none was evaluated.
     */
    public function rate(): ?float
    {
        $evaluated = $this->evaluated();

        return $evaluated === 0 ? null : $this->fulfilled / $evaluated * 100;
    }
}
