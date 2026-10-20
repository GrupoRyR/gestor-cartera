<?php

namespace App\Modules\Core\Console\Migracion;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saves the users of the legacy systems. A new user is created active or
 * not as in the source, without Microsoft id or password. A user already in
 * the app (same e-mail) keeps their name, activation and Microsoft id: only
 * the companies, SGX advisor links and team advisors they lack are added,
 * so a second run, or the same person in both sources, adds nothing twice.
 * A user deleted in the app is never restored.
 *
 * A user has one role at most, as on the Equipo screen: the role is set
 * only when the user has none, so the first source that brings a person
 * decides it; a different role in a later source is a warning.
 *
 * Every change goes to the audit trail with the actions and change fields
 * of the Equipo screen and the source "migracion".
 */
final class LegacyUsers
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Returns whether the user was created or something was added to them.
     * What cannot be added (another role, unknown or taken advisor codes)
     * becomes a warning on $report.
     *
     * @throws RowRejected When the user is deleted in the app.
     */
    public function save(LegacyUser $legacy, CsvRow $row, ImportReport $report): bool
    {
        $existing = User::withTrashed()->where('email', $legacy->email)->lockForUpdate()->first();

        if ($existing?->trashed()) {
            throw new RowRejected('el usuario está eliminado en la app');
        }

        $user = $existing ?? User::withTrashed()->restoreOrCreate(
            ['email' => $legacy->email],
            ['name' => $legacy->name, 'is_active' => $legacy->active],
        );
        $context = ['source' => 'migracion', 'file' => $row->file, 'line' => $row->line];
        $role = $this->setRole($user, $legacy, $row, $report);

        // The codes belong to the source's role: an advisor's own codes, a
        // manager's team. A user who kept another role gets neither.
        $advisorCodes = $this->codesFor(Role::SalesAdvisor, $role['new'], $legacy->advisorCodes, $row, $report);
        $teamCodes = $this->codesFor(Role::SalesManager, $role['new'], $legacy->teamCodes, $row, $report);

        $changes = array_filter([
            'role' => $role,
            'companies' => $this->addCompanies($user, $legacy->companies),
            'team' => $this->addTeam($user, $teamCodes, $row, $report),
        ], fn (array $change): bool => $change['old'] !== $change['new']);

        if ($existing === null) {
            $this->audit->record('core.user.created', 'user', $user->id, [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $legacy->role->value,
                'companies' => $changes['companies']['new'] ?? [],
                'active' => $legacy->active,
                'team' => $changes['team']['new'] ?? [],
            ], $context);
        } elseif ($changes !== []) {
            $this->audit->record('core.user.updated', 'user', $user->id, $changes, $context);
        }

        $linked = $this->linkAdvisors($user, $advisorCodes, $row, $report, $context);

        return $existing === null || $changes !== [] || $linked;
    }

    /**
     * Gives $user the role of $legacy when they have none; otherwise keeps
     * theirs and warns when the source says something else.
     *
     * @return array{old: string|null, new: string|null}
     */
    private function setRole(User $user, LegacyUser $legacy, CsvRow $row, ImportReport $report): array
    {
        $current = $user->getRoleNames()->first();

        if ($current === null) {
            $user->assignRole(SpatieRole::findOrCreate($legacy->role->value, 'web'));

            return ['old' => null, 'new' => $legacy->role->value];
        }

        if ($current !== $legacy->role->value) {
            $source = LegacySource::ofFile($row->file)->label();
            $report->warn($row, "ya tiene el rol {$current}; {$source} dice {$legacy->role->value}, se conserva el actual");
        }

        return ['old' => $current, 'new' => $current];
    }

    /**
     * $codes when the user's role (after setRole()) is $role; otherwise
     * none, with a warning when there were codes to link.
     *
     * @param  list<int>  $codes
     * @return list<int>
     */
    private function codesFor(Role $role, ?string $kept, array $codes, CsvRow $row, ImportReport $report): array
    {
        if ($codes === [] || $kept === $role->value) {
            return $codes;
        }

        $report->warn($row, 'no se vinculan los códigos '.implode(', ', $codes).': el usuario conserva el rol '.($kept ?? 'ninguno'));

        return [];
    }

    /**
     * @param  list<CompanyCode>  $companies
     * @return array{old: list<string>, new: list<string>} The company codes before and after, sorted.
     */
    private function addCompanies(User $user, array $companies): array
    {
        $old = CompanyRecord::assignedTo($user)->pluck('companies.code')
            ->map(fn (CompanyCode $code): string => $code->value)
            ->sort()
            ->values()
            ->all();

        $added = array_values(array_diff(array_map(fn (CompanyCode $code): string => $code->value, $companies), $old));

        if ($added !== []) {
            CompanyRecord::assignedTo($user)->attach($added);
        }

        $new = array_values(array_unique([...$old, ...$added]));
        sort($new);

        return ['old' => $old, 'new' => $new];
    }

    /**
     * Adds the SGX advisors of $codes to the team of $user, a sales
     * manager, keeping the advisors already in it.
     *
     * @param  list<int>  $codes
     * @return array{old: list<string>, new: list<string>} The team before and after as «SGX-84» labels, sorted.
     */
    private function addTeam(User $user, array $codes, CsvRow $row, ImportReport $report): array
    {
        $old = $this->teamLabels($user);

        if ($codes === []) {
            return ['old' => $old, 'new' => $old];
        }

        $advisors = AdvisorRecord::query()
            ->where('company', CompanyCode::SGX->value)
            ->whereIn('code', $codes)
            ->pluck('id', 'code');

        foreach ($codes as $code) {
            if (! $advisors->has($code)) {
                $report->warn($row, "el código de asesor {$code} no existe en la app (SGX)");
            }
        }

        AdvisorRecord::teamOf($user)->syncWithoutDetaching($advisors->values()->all());

        return ['old' => $old, 'new' => $this->teamLabels($user)];
    }

    /**
     * @return list<string>
     */
    private function teamLabels(User $user): array
    {
        $labels = AdvisorRecord::teamOf($user)->get()
            ->map(fn (AdvisorRecord $advisor): string => "{$advisor->company->value}-{$advisor->code}")
            ->all();
        sort($labels, SORT_NATURAL);

        return $labels;
    }

    /**
     * Links the free SGX advisors of $codes to $user. An advisor already
     * linked to someone else is never taken over.
     *
     * @param  list<int>  $codes
     * @param  array<string, string|int>  $context
     * @return bool Whether some advisor was linked.
     */
    private function linkAdvisors(User $user, array $codes, CsvRow $row, ImportReport $report, array $context): bool
    {
        $linked = false;

        foreach ($codes as $code) {
            $advisor = AdvisorRecord::query()
                ->where('company', CompanyCode::SGX->value)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($advisor === null) {
                $report->warn($row, "el código de asesor {$code} no existe en la app (SGX)");

                continue;
            }

            if ($advisor->user_id === $user->id) {
                continue;
            }

            if ($advisor->user_id !== null) {
                $report->warn($row, "el código de asesor {$code} ya está vinculado a otro usuario");

                continue;
            }

            $advisor->user_id = $user->id;
            $advisor->save();
            $linked = true;

            $this->audit->record(
                'core.advisor.linked',
                'advisor',
                $advisor->id,
                ['user_id' => ['old' => null, 'new' => $user->id]],
                ['company' => CompanyCode::SGX->value, 'code' => $code, ...$context],
            );
        }

        return $linked;
    }
}
