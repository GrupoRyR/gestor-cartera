<?php

namespace App\Modules\Core\Application\Auth;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Auth\SendAdvisorRoleNotice;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Turns the identity Microsoft sent back into the user who signs in (Part
 * A.5: "la app lo empareja por correo con su rol y con su asesor de SAP").
 *
 * 1. The "tid" claim must be the configured tenant, and the account a
 *    member of it: guests are turned away (audit S-17).
 * 2. The user is found by e-mail. A removed, deactivated or differently
 *    linked user is turned away; an unknown one is created without a role.
 * 3. The active, unlinked SAP advisors with the same e-mail are linked, only
 *    for e-mails of the company domains (core.auth.company_domains).
 * 4. A user without a role for whom step 3 linked an advisor becomes
 *    "asesor" of those advisors' companies, and IT gets a notice once the
 *    transaction commits. Advisors linked earlier never give a role back. A
 *    user with a role keeps it untouched.
 *
 * Steps 2 to 4 run in one transaction. Every change and every rejection goes
 * to the audit trail; tokens never do.
 */
final class SignInWithMicrosoft
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $database,
        private readonly Config $config,
    ) {}

    /**
     * @throws SignInRejected When the account may not sign in.
     */
    public function handle(MicrosoftIdentity $identity): User
    {
        try {
            $this->ensureConfiguredTenant($identity);

            if ($identity->guest) {
                throw new SignInRejected(RejectionReason::Guest);
            }

            return $this->database->transaction(function () use ($identity): User {
                $user = $this->registeredUser($identity);

                $this->assignAdvisorRole($user, $this->linkAdvisors($user));

                return $user;
            });
        } catch (SignInRejected $rejected) {
            // After the rollback, so the entry stays.
            $this->audit->record('core.auth.rejected', context: [
                'reason' => $rejected->reason->value,
                'email' => $identity->email,
            ]);

            throw $rejected;
        }
    }

    /**
     * Fails closed: without a configured tenant nobody signs in. Tenant ids
     * are GUIDs, which compare without regard to case.
     */
    private function ensureConfiguredTenant(MicrosoftIdentity $identity): void
    {
        $configured = $this->config->get('services.microsoft.tenant');
        $tenant = is_string($configured) ? trim($configured) : '';

        if ($tenant === '' || $identity->tenantId === null || strcasecmp($tenant, $identity->tenantId) !== 0) {
            throw new SignInRejected(RejectionReason::Tenant);
        }
    }

    /**
     * The user of the e-mail, created on the first sign-in. Removed users are
     * not restored here: an admin restores them from Equipo.
     */
    private function registeredUser(MicrosoftIdentity $identity): User
    {
        $user = User::withTrashed()->where('email', $identity->email)->lockForUpdate()->first();
        $owner = User::withTrashed()->where('microsoft_id', $identity->objectId)->first();

        if ($user !== null && $user->trashed()) {
            throw new SignInRejected(RejectionReason::Deleted);
        }

        if (($owner !== null && ! $owner->is($user))
            || ($user?->microsoft_id !== null && $user->microsoft_id !== $identity->objectId)) {
            throw new SignInRejected(RejectionReason::IdentityMismatch);
        }

        if ($user !== null && ! $user->is_active) {
            throw new SignInRejected(RejectionReason::Inactive);
        }

        if ($user === null) {
            return $this->createUser($identity);
        }

        $this->refreshProfile($user, $identity);

        return $user;
    }

    private function createUser(MicrosoftIdentity $identity): User
    {
        $user = User::withTrashed()->restoreOrCreate(
            ['email' => $identity->email],
            ['name' => $identity->name, 'microsoft_id' => $identity->objectId],
        );

        $this->audit->record('core.user.created', 'user', $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'microsoft_id' => $user->microsoft_id,
        ], ['source' => 'microsoft']);

        return $user;
    }

    /**
     * Links the Microsoft account the first time and keeps the name in step
     * with Entra ID.
     */
    private function refreshProfile(User $user, MicrosoftIdentity $identity): void
    {
        $changes = [];

        if ($user->microsoft_id === null) {
            $changes['microsoft_id'] = ['old' => null, 'new' => $identity->objectId];
        }

        if ($user->name !== $identity->name) {
            $changes['name'] = ['old' => $user->name, 'new' => $identity->name];
        }

        if ($changes === []) {
            return;
        }

        $user->forceFill(array_map(fn (array $change): string => $change['new'], $changes))->save();

        $this->audit->record('core.user.updated', 'user', $user->id, $changes, ['source' => 'microsoft']);
    }

    /**
     * Links the active SAP advisors of the user's e-mail (OSLP.Email) that
     * nobody claimed yet. Advisors linked to someone else stay theirs. An
     * e-mail outside the company domains links none: whoever can edit
     * OSLP.Email must not hand the clients of an advisor to an outside
     * account (audit S-17).
     *
     * @return list<AdvisorRecord> The advisors linked by this sign-in.
     */
    private function linkAdvisors(User $user): array
    {
        if (! $this->hasCompanyDomain($user->email)) {
            return [];
        }

        $advisors = AdvisorRecord::query()
            ->where('email', $user->email)
            ->where('active', true)
            ->whereNull('user_id')
            ->orderBy('company')
            ->orderBy('code')
            ->lockForUpdate()
            ->get();

        foreach ($advisors as $advisor) {
            $advisor->user_id = $user->id;
            $advisor->save();

            $this->audit->record(
                'core.advisor.linked',
                'advisor',
                $advisor->id,
                ['user_id' => ['old' => null, 'new' => $user->id]],
                ['company' => $advisor->company->value, 'code' => $advisor->code],
            );
        }

        return array_values($advisors->all());
    }

    /**
     * Whether the domain of $email is exactly one of core.auth.company_domains.
     * The e-mail is already normalized (User::normalizeEmail()).
     */
    private function hasCompanyDomain(string $email): bool
    {
        $at = strrpos($email, '@');
        $domains = array_map(mb_strtolower(...), array_filter((array) $this->config->get('core.auth.company_domains', []), is_string(...)));

        return $at !== false && in_array(substr($email, $at + 1), $domains, true);
    }

    /**
     * A user without a role for whom this sign-in linked an advisor becomes
     * "asesor" of the companies of those advisors. Advisors linked before do
     * not count: an admin who took every role away to revoke the access must
     * not see it come back at the next sign-in. IT and internal control get a
     * notice of every role given here (audit S-17).
     *
     * @param  list<AdvisorRecord>  $linked  The advisors linked by this sign-in.
     */
    private function assignAdvisorRole(User $user, array $linked): void
    {
        if ($linked === [] || $user->roles()->exists()) {
            return;
        }

        $advisorCompanies = array_map(fn (AdvisorRecord $advisor): CompanyCode => $advisor->company, $linked);
        $companies = array_values(array_map(
            fn (CompanyCode $code): string => $code->value,
            array_filter(CompanyCode::cases(), fn (CompanyCode $code): bool => in_array($code, $advisorCompanies, true)),
        ));

        if ($companies === []) {
            return;
        }

        $user->assignRole(SpatieRole::findOrCreate(Role::SalesAdvisor->value, 'web'));
        CompanyRecord::assignedTo($user)->syncWithoutDetaching($companies);

        $this->audit->record('core.user.role_assigned', 'user', $user->id, [
            'role' => Role::SalesAdvisor->value,
            'companies' => $companies,
        ], ['source' => 'microsoft']);

        SendAdvisorRoleNotice::dispatch(
            $user->id,
            $companies,
            array_map(fn (AdvisorRecord $advisor): array => ['company' => $advisor->company->value, 'code' => $advisor->code], $linked),
            CarbonImmutable::now()->toIso8601String(),
        )->afterCommit();
    }
}
