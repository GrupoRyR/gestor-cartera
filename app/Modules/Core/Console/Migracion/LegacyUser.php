<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;

/**
 * A user of a legacy system, already mapped to the role, companies and SGX
 * advisor codes of this app.
 */
final readonly class LegacyUser
{
    /**
     * @param  string  $email  Normalized (User::normalizeEmail()).
     * @param  list<CompanyCode>  $companies
     * @param  list<int>  $advisorCodes  SAP SlpCodes of SGX to link to the user (an advisor's own codes).
     * @param  list<int>  $teamCodes  SAP SlpCodes of SGX to add to the user's team (a sales manager's advisors).
     */
    public function __construct(
        public string $email,
        public string $name,
        public bool $active,
        public Role $role,
        public array $companies,
        public array $advisorCodes = [],
        public array $teamCodes = [],
    ) {}
}
