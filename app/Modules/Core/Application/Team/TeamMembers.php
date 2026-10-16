<?php

namespace App\Modules\Core\Application\Team;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Database\ConnectionInterface;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The writes of the Equipo screen (Part B, Módulo 0N, points 2 and 6):
 * pre-register, edit, soft-delete and restore users.
 *
 * Every change goes to the audit trail with the context source "equipo"
 * (the audit logger adds the admin as the actor); a refused change writes
 * nothing. Lock-out guards: an admin may not deactivate, delete or take the
 * admin role away from themselves.
 */
final class TeamMembers
{
    private const array CONTEXT = ['source' => 'equipo'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
    ) {}

    /**
     * Registers a person before their first Microsoft sign-in, active. The
     * e-mail of a deleted user restores that same row (the e-mail is unique
     * even among deleted users) and applies $details to it; the returned
     * user's wasRecentlyCreated tells both cases apart.
     *
     * @throws TeamChangeRefused When the e-mail belongs to a user who is not deleted.
     */
    public function create(string $email, TeamMemberDetails $details): User
    {
        return $this->database->transaction(function () use ($email, $details): User {
            $email = User::normalizeEmail($email);
            $existing = User::withTrashed()->where('email', $email)->lockForUpdate()->first();

            if ($existing !== null && ! $existing->trashed()) {
                throw new TeamChangeRefused(TeamRefusal::EmailTaken);
            }

            $user = User::withTrashed()->restoreOrCreate(
                ['email' => $email],
                ['name' => $details->name, 'is_active' => $details->active],
            );

            if (! $user->wasRecentlyCreated) {
                $this->audit->record('core.user.restored', 'user', $user->id, $this->identity($user), self::CONTEXT);
                $this->apply($user, $details);

                return $user;
            }

            $this->saveRole($user, $details->role);
            CompanyRecord::assignedTo($user)->sync($details->companyCodes());

            $this->audit->record('core.user.created', 'user', $user->id, [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $details->role?->value,
                'companies' => $details->companyCodes(),
            ], self::CONTEXT);

            return $user;
        });
    }

    /**
     * Saves the name, role, companies and activation of $member. Only the
     * fields that change are written and audited; no change, no entry.
     *
     * @throws TeamChangeRefused When $actor would deactivate themselves or drop their admin role.
     */
    public function update(User $actor, User $member, TeamMemberDetails $details): void
    {
        if ($member->is($actor)) {
            if (! $details->active) {
                throw new TeamChangeRefused(TeamRefusal::SelfDeactivation);
            }

            if ($member->hasRole(Role::Admin->value) && $details->role !== Role::Admin) {
                throw new TeamChangeRefused(TeamRefusal::SelfDemotion);
            }
        }

        $this->database->transaction(fn () => $this->apply($member, $details));
    }

    /**
     * Soft-deletes $member: the row, its role and its companies stay for
     * the audit trail and a later restore, but the session guard and the
     * Microsoft sign-in no longer find them.
     *
     * @throws TeamChangeRefused When $actor would delete themselves.
     */
    public function delete(User $actor, User $member): void
    {
        if ($member->is($actor)) {
            throw new TeamChangeRefused(TeamRefusal::SelfDeletion);
        }

        $this->database->transaction(function () use ($member): void {
            $member->delete();

            $this->audit->record('core.user.deleted', 'user', $member->id, $this->identity($member), self::CONTEXT);
        });
    }

    /**
     * Brings a soft-deleted user back as they were. Returns false, and
     * writes nothing, when $member was not deleted.
     */
    public function restore(User $member): bool
    {
        if (! $member->trashed()) {
            return false;
        }

        $this->database->transaction(function () use ($member): void {
            $member->restore();

            $this->audit->record('core.user.restored', 'user', $member->id, $this->identity($member), self::CONTEXT);
        });

        return true;
    }

    private function apply(User $member, TeamMemberDetails $details): void
    {
        $before = $this->snapshot($member);
        $after = [
            'name' => $details->name,
            'role' => $details->role?->value,
            'companies' => $details->companyCodes(),
            'active' => $details->active,
        ];

        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['old' => $before[$field], 'new' => $value];
            }
        }

        if ($changes === []) {
            return;
        }

        $member->fill(['name' => $details->name, 'is_active' => $details->active])->save();

        if (array_key_exists('role', $changes)) {
            $this->saveRole($member, $details->role);
        }

        if (array_key_exists('companies', $changes)) {
            CompanyRecord::assignedTo($member)->sync($after['companies']);
        }

        $this->audit->record('core.user.updated', 'user', $member->id, $changes, self::CONTEXT);
    }

    /**
     * The audited fields of $member as stored: role as its value, companies
     * as sorted codes.
     *
     * @return array{name: string, role: string|null, companies: list<string>, active: bool}
     */
    private function snapshot(User $member): array
    {
        $companies = CompanyRecord::assignedTo($member)->pluck('companies.code')
            ->map(fn (CompanyCode $code): string => $code->value)
            ->values()
            ->all();
        sort($companies);

        return [
            'name' => $member->name,
            'role' => $member->getRoleNames()->first(),
            'companies' => $companies,
            'active' => (bool) $member->is_active,
        ];
    }

    /**
     * One role or none (Spatie roles are global to the user).
     */
    private function saveRole(User $member, ?Role $role): void
    {
        $member->syncRoles($role === null ? [] : [SpatieRole::findOrCreate($role->value, 'web')]);
    }

    /**
     * @return array{name: string, email: string}
     */
    private function identity(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email];
    }
}
