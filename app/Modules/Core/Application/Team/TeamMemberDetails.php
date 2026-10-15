<?php

namespace App\Modules\Core\Application\Team;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;

/**
 * What the Equipo forms set on a user: the name, one role or none, the
 * companies and whether the account is active.
 */
final readonly class TeamMemberDetails
{
    /**
     * @param  list<CompanyCode>  $companies
     */
    public function __construct(
        public string $name,
        public ?Role $role,
        public array $companies = [],
        public bool $active = true,
    ) {}

    /**
     * The companies to store, as sorted codes. Admins see every company
     * without assignments (see CompanyAccess), so theirs are dropped.
     *
     * @return list<string>
     */
    public function companyCodes(): array
    {
        if ($this->role === Role::Admin) {
            return [];
        }

        $codes = array_values(array_unique(array_map(fn (CompanyCode $code): string => $code->value, $this->companies)));
        sort($codes);

        return $codes;
    }
}
