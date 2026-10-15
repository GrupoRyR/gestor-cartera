<?php

namespace App\Modules\Core\Application\Team;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * The writes of the Equipo screen (Part B, Módulo 0N, points 2 and 6):
 * pre-register, edit, soft-delete and restore users, link SAP advisors to
 * them and set the team of a sales manager.
 *
 * Every change goes to the audit trail with the context source "equipo"
 * (the audit logger adds the admin as the actor); a refused change writes
 * nothing. Lock-out guards: an admin may not deactivate, delete or take the
 * admin role away from themselves. An advisor belongs to one user at most:
 * one linked to someone else is never taken over from here.
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
     * @throws TeamChangeRefused When the e-mail belongs to a user who is not deleted, or an advisor to link belongs to someone else.
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
            AdvisorRecord::teamOf($user)->sync($details->teamIds());

            $this->audit->record('core.user.created', 'user', $user->id, [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $details->role?->value,
                'companies' => $details->companyCodes(),
                'team' => $this->teamLabels(AdvisorRecord::query()->whereKey($details->teamIds())->get()),
            ], self::CONTEXT);

            $this->linkAdvisors($user, $details->advisorIds());

            return $user;
        });
    }

    /**
     * Saves the name, role, companies, activation, team and linked advisors
     * of $member. Only what changes is written and audited; no change, no
     * entry.
     *
     * @throws TeamChangeRefused When $actor would deactivate themselves or drop their admin role, or an advisor to link belongs to someone else.
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
            'team' => $this->teamLabels(AdvisorRecord::query()->whereKey($details->teamIds())->get()),
        ];

        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['old' => $before[$field], 'new' => $value];
            }
        }

        if ($changes !== []) {
            $member->fill(['name' => $details->name, 'is_active' => $details->active])->save();

            if (array_key_exists('role', $changes)) {
                $this->saveRole($member, $details->role);
            }

            if (array_key_exists('companies', $changes)) {
                CompanyRecord::assignedTo($member)->sync($after['companies']);
            }

            if (array_key_exists('team', $changes)) {
                AdvisorRecord::teamOf($member)->sync($details->teamIds());
            }

            $this->audit->record('core.user.updated', 'user', $member->id, $changes, self::CONTEXT);
        }

        $this->linkAdvisors($member, $details->advisorIds());
    }

    /**
     * Links the advisors of $advisorIds to $member and unlinks the other
     * advisors linked to them, with one audit entry per advisor that
     * changes. The rows are locked first, so an advisor linked to someone
     * else in the meantime is refused, not taken over.
     *
     * @param  list<int>  $advisorIds
     *
     * @throws TeamChangeRefused When one of $advisorIds is linked to someone else.
     */
    private function linkAdvisors(User $member, array $advisorIds): void
    {
        $advisors = AdvisorRecord::query()
            ->where(fn (Builder $query) => $query->whereKey($advisorIds)->orWhere('user_id', $member->id))
            ->orderBy('company')
            ->orderBy('code')
            ->lockForUpdate()
            ->get();

        foreach ($advisors as $advisor) {
            if ($advisor->user_id !== null && $advisor->user_id !== $member->id) {
                throw new TeamChangeRefused(TeamRefusal::AdvisorTaken);
            }
        }

        foreach ($advisors as $advisor) {
            $linked = in_array($advisor->id, $advisorIds, true);
            $old = $advisor->user_id;
            $new = $linked ? $member->id : null;

            if ($old === $new) {
                continue;
            }

            $advisor->user_id = $new;
            $advisor->save();

            $this->audit->record(
                $linked ? 'core.advisor.linked' : 'core.advisor.unlinked',
                'advisor',
                $advisor->id,
                ['user_id' => ['old' => $old, 'new' => $new]],
                ['company' => $advisor->company->value, 'code' => $advisor->code, ...self::CONTEXT],
            );
        }
    }

    /**
     * The audited fields of $member as stored: role as its value, companies
     * as sorted codes, team as sorted advisor labels.
     *
     * @return array{name: string, role: string|null, companies: list<string>, active: bool, team: list<string>}
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
            'team' => $this->teamLabels(AdvisorRecord::teamOf($member)->get()),
        ];
    }

    /**
     * A team as the audit trail shows it: «<COMPANY>-<code>» labels, sorted.
     *
     * @param  iterable<AdvisorRecord>  $advisors
     * @return list<string>
     */
    private function teamLabels(iterable $advisors): array
    {
        $labels = [];

        foreach ($advisors as $advisor) {
            $labels[] = "{$advisor->company->value}-{$advisor->code}";
        }

        sort($labels, SORT_NATURAL);

        return $labels;
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
