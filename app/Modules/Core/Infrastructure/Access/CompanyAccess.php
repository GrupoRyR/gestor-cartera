<?php

namespace App\Modules\Core\Infrastructure\Access;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Which companies a user may see (Part A.5). Spatie roles are global to the
 * user; the company comes from company_user, except for admins (IT), who
 * have no company assignment and see every company.
 */
final class CompanyAccess
{
    /**
     * The companies $user may see, in CompanyCode case order. None for
     * guests, unsaved, inactive or deleted users, and users without a role.
     *
     * @return list<CompanyCode>
     */
    public function companiesFor(?Authenticatable $user): array
    {
        if (! $user instanceof User || $user->getKey() === null || ! $this->isEnabled($user)) {
            return [];
        }

        $roles = $user->getRoleNames();

        if ($roles->isEmpty()) {
            return [];
        }

        if ($roles->contains(Role::Admin->value)) {
            return CompanyCode::cases();
        }

        $assigned = CompanyRecord::assignedTo($user)->pluck('companies.code')->all();

        return array_values(array_filter(
            CompanyCode::cases(),
            fn (CompanyCode $code): bool => in_array($code, $assigned, true),
        ));
    }

    /**
     * Active and not deleted. Reads the raw attributes: a user created in
     * this request (or by a factory) may lack is_active or deleted_at, and
     * strict models would throw instead of applying the column defaults
     * (active, not deleted).
     */
    private function isEnabled(User $user): bool
    {
        $attributes = $user->getAttributes();

        return (bool) ($attributes['is_active'] ?? true)
            && ($attributes[$user->getDeletedAtColumn()] ?? null) === null;
    }
}
