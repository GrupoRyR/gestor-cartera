<?php

namespace App\Modules\Core\Contracts\Exceptions;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The user asked CurrentCompany::switchTo() for a company, or for the group
 * view, that they may not see.
 */
final class CompanyNotAvailable extends ContractException
{
    /**
     * @param  CompanyCode|null  $company  The requested company; null for the group view.
     */
    public function __construct(
        public readonly ?CompanyCode $company,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function forCompany(CompanyCode $company): self
    {
        return new self($company, "Company {$company->value} is not available to the current user.");
    }

    public static function groupView(): self
    {
        return new self(null, 'The group view is not available to the current user.');
    }
}
