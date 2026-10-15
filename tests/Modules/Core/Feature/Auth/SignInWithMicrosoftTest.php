<?php

use App\Models\User;
use App\Modules\Core\Application\Auth\SignInWithMicrosoft;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\FakeIdentityProvider;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    config(['services.microsoft.tenant' => FakeIdentityProvider::TENANT]);
});

/**
 * Runs the sign-in of $email, as the Microsoft callback does.
 */
function signInWithMicrosoft(string $email, string $objectId = 'oid-ana'): User
{
    return app(SignInWithMicrosoft::class)->handle(FakeIdentityProvider::identity($email, $objectId));
}

/**
 * The codes of the companies $user is assigned to, in code order.
 *
 * @return list<string>
 */
function assignedCompanies(User $user): array
{
    return $user->companies()->orderBy('companies.code')->pluck('companies.code')
        ->map(fn (CompanyCode $code): string => $code->value)
        ->all();
}

describe('advisor matching', function () {
    it('links the active, unlinked advisors that share the e-mail', function () {
        $advisor = AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 12, 'email' => 'Ana.Gomez@Segurex.com']);
        $inactive = AdvisorRecord::factory()->inactive()->create(['company' => CompanyCode::SDC, 'code' => 7, 'email' => 'ana.gomez@segurex.com']);
        $someoneElse = User::factory()->create();
        $taken = AdvisorRecord::factory()->linkedTo($someoneElse)->create(['company' => CompanyCode::SDC, 'code' => 8, 'email' => 'ana.gomez@segurex.com']);
        $stranger = AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 13, 'email' => 'pedro.ruiz@segurex.com']);

        $user = signInWithMicrosoft('ana.gomez@segurex.com');
        $linked = AuditLogRecord::query()->where('action', 'core.advisor.linked')->sole();

        expect($advisor->refresh()->user_id)->toBe($user->id)
            ->and($inactive->refresh()->user_id)->toBeNull()
            ->and($taken->refresh()->user_id)->toBe($someoneElse->id)
            ->and($stranger->refresh()->user_id)->toBeNull()
            ->and($linked->subject_type)->toBe('advisor')
            ->and($linked->subject_id)->toBe((string) $advisor->id)
            ->and($linked->changes)->toBe(['user_id' => ['old' => null, 'new' => $user->id]])
            ->and($linked->context)->toBe(['company' => 'SGX', 'code' => 12]);
    });

    it('gives no role without an active advisor', function () {
        AdvisorRecord::factory()->inactive()->create(['email' => 'ana.gomez@segurex.com']);

        $user = signInWithMicrosoft('ana.gomez@segurex.com');

        expect($user->getRoleNames()->all())->toBe([])
            ->and(assignedCompanies($user))->toBe([])
            ->and(AuditLogRecord::query()->where('action', 'core.user.role_assigned')->exists())->toBeFalse();
    });
});

describe('role matching', function () {
    it('makes a user without a role the advisor of the companies of their advisors', function () {
        AdvisorRecord::factory()->create(['company' => CompanyCode::SDC, 'code' => 5, 'email' => 'ana.gomez@segurex.com']);
        AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 12, 'email' => 'ana.gomez@segurex.com']);

        $user = signInWithMicrosoft('ana.gomez@segurex.com');
        $assigned = AuditLogRecord::query()->where('action', 'core.user.role_assigned')->sole();

        expect($user->fresh()?->getRoleNames()->all())->toBe([Role::SalesAdvisor->value])
            ->and($user->can('core.access'))->toBeTrue()
            ->and(assignedCompanies($user))->toBe(['SDC', 'SGX'])
            ->and($assigned->subject_type)->toBe('user')
            ->and($assigned->subject_id)->toBe((string) $user->id)
            ->and($assigned->changes)->toBe(['role' => 'asesor', 'companies' => ['SGX', 'SDC']])
            ->and($assigned->context)->toBe(['source' => 'microsoft']);
    });

    it('assigns the role from an advisor linked before, without duplicating companies', function () {
        $user = CoreUsers::create(null, CompanyCode::SGX);
        $user->update(['email' => 'ana.gomez@segurex.com', 'microsoft_id' => 'oid-ana']);
        AdvisorRecord::factory()->linkedTo($user)->create(['company' => CompanyCode::SGX, 'email' => null]);

        signInWithMicrosoft('ana.gomez@segurex.com');

        expect($user->fresh()?->getRoleNames()->all())->toBe([Role::SalesAdvisor->value])
            ->and(assignedCompanies($user))->toBe(['SGX']);
    });

    it('never changes the role or the companies of a user who already has a role', function () {
        $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX);
        $user->update(['email' => 'ana.gomez@segurex.com', 'microsoft_id' => 'oid-ana']);
        $advisor = AdvisorRecord::factory()->create(['company' => CompanyCode::SDC, 'email' => 'ana.gomez@segurex.com']);

        signInWithMicrosoft('ana.gomez@segurex.com');

        expect($user->fresh()?->getRoleNames()->all())->toBe([Role::PortfolioAnalyst->value])
            ->and(assignedCompanies($user))->toBe(['SGX'])
            ->and($advisor->refresh()->user_id)->toBe($user->id)
            ->and(AuditLogRecord::query()->where('action', 'core.user.role_assigned')->exists())->toBeFalse();
    });
});
