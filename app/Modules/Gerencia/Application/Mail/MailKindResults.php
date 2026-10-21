<?php

namespace App\Modules\Gerencia\Application\Mail;

use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Gerencia\Domain\Mail\MailRunTotals;

/**
 * The runs of one kind of collection mail in the period: the total of the
 * companies in the user's scope and each of them.
 */
final readonly class MailKindResults
{
    /**
     * @param  list<MailRunTotals>  $companies  One per company in the scope, in CompanyCode case order.
     */
    public function __construct(
        public MailRunKind $kind,
        public MailRunTotals $total,
        public array $companies,
    ) {}

    /**
     * Whether any company ran this kind of mail in the period.
     */
    public function hasRuns(): bool
    {
        return $this->total->hasRuns();
    }
}
