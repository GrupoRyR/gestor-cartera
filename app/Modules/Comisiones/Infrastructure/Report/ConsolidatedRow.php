<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * One row of section 1 of the consolidated report (§8.1): a regular code,
 * a derived commission (81.1, 85.1) or the total of the section.
 */
final readonly class ConsolidatedRow
{
    /**
     * @param  string  $reference  The code ("14"), the derived key ("81.1") or empty for the total.
     * @param  int|null  $code  The code to drill down to; null for a derived row and the total.
     * @param  Money  $director  The director commission placed on the code (§14.4); zero when paid apart.
     */
    public function __construct(
        public string $reference,
        public string $name,
        public ?int $code,
        public Money $saleCommission,
        public Money $collectionCommission,
        public Money $obrCommission,
        public Money $reimbursements,
        public Money $deductions,
        public Money $director,
        public Money $total,
    ) {}
}
