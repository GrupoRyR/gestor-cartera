<?php

namespace App\Modules\Bancos\Application\Access;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * Which companies' bank movements the user may see. Bank movements have no
 * owner other than the company, so Core's CurrentCompany is the whole rule:
 * the lists follow the company (or group view) the user picked, and a single
 * movement or an upload is allowed for any company the user may switch to.
 */
final class VisibleCompanies
{
    public function __construct(private readonly CurrentCompany $current) {}

    /**
     * The companies of the current view: the active one, or all of the user's in the group view.
     *
     * @return list<CompanyCode>
     */
    public function inView(): array
    {
        return $this->codes($this->current->scope());
    }

    /**
     * Every company the user may choose.
     *
     * @return list<CompanyCode>
     */
    public function available(): array
    {
        return $this->codes($this->current->available());
    }

    public function canSee(CompanyCode $company): bool
    {
        return in_array($company, $this->available(), true);
    }

    /**
     * @param  list<Company>  $companies
     * @return list<CompanyCode>
     */
    private function codes(array $companies): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $companies);
    }
}
