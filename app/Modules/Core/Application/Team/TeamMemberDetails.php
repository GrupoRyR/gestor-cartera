<?php

namespace App\Modules\Core\Application\Team;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;

/**
 * What the Equipo forms set on a user: the name, one role or none, the
 * companies, whether the account is active, the SAP advisors linked to them
 * and, for a sales manager, the advisors of their team.
 */
final readonly class TeamMemberDetails
{
    /**
     * @param  list<CompanyCode>  $companies
     * @param  list<int>  $advisors  Ids of the SAP advisors linked to the user (advisors.user_id).
     * @param  list<int>  $team  Ids of the advisors in the user's team as a sales manager (advisor_team).
     */
    public function __construct(
        public string $name,
        public ?Role $role,
        public array $companies = [],
        public bool $active = true,
        public array $advisors = [],
        public array $team = [],
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

    /**
     * The advisors to link, as sorted ids.
     *
     * @return list<int>
     */
    public function advisorIds(): array
    {
        return self::sorted($this->advisors);
    }

    /**
     * The team to store, as sorted ids. Only a sales manager has a team
     * (Part A.5: "ve la cartera de los asesores de su equipo"), so any other
     * role stores none.
     *
     * @return list<int>
     */
    public function teamIds(): array
    {
        return $this->role === Role::SalesManager ? self::sorted($this->team) : [];
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function sorted(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
