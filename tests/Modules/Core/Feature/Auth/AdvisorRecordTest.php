<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps the SAP sales employees of each company', function () {
    $advisor = AdvisorRecord::factory()->create([
        'company' => CompanyCode::SDC,
        'code' => 14,
        'name' => 'Pedro Ruiz',
        'email' => '  Pedro.Ruiz@DerivadosCol.com ',
    ])->refresh();

    expect($advisor->company)->toBe(CompanyCode::SDC)
        ->and($advisor->code)->toBe(14)
        ->and($advisor->name)->toBe('Pedro Ruiz')
        ->and($advisor->email)->toBe('pedro.ruiz@derivadoscol.com')
        ->and($advisor->active)->toBeTrue()
        ->and($advisor->user_id)->toBeNull();
});

it('accepts advisors without an e-mail', function (?string $email) {
    expect(AdvisorRecord::factory()->create(['email' => $email])->refresh()->email)->toBeNull();
})->with(['null' => [null], 'blank' => ['  ']]);

it('makes active, unlinked advisors of Segurex with a Colombian name and an e-mail by default', function () {
    $advisor = AdvisorRecord::factory()->make();

    expect($advisor->company)->toBe(CompanyCode::SGX)
        ->and($advisor->active)->toBeTrue()
        ->and($advisor->user_id)->toBeNull()
        ->and($advisor->name)->toMatch('/^\S+ \S+$/u')
        ->and($advisor->email)->toBe(User::normalizeEmail((string) $advisor->email))
        ->and($advisor->email)->toEndWith('@segurex.com');
});

it('allows one advisor per company and SAP code', function () {
    AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 7]);
    AdvisorRecord::factory()->create(['company' => CompanyCode::SDC, 'code' => 7]);

    expect(fn () => AdvisorRecord::factory()->create(['company' => CompanyCode::SGX, 'code' => 7]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lists the advisors linked to a user', function () {
    $user = User::factory()->create();
    AdvisorRecord::factory()->linkedTo($user)->create(['company' => CompanyCode::SGX, 'code' => 3]);
    AdvisorRecord::factory()->linkedTo($user)->inactive()->create(['company' => CompanyCode::SDC, 'code' => 9]);
    AdvisorRecord::factory()->create(['code' => 4]);

    expect($user->advisors()->orderBy('code')->pluck('code')->all())->toBe([3, 9])
        ->and(AdvisorRecord::query()->where('code', 9)->sole()->active)->toBeFalse();
});

it('keeps the advisor when its user is removed for good', function () {
    $user = User::factory()->create();
    $advisor = AdvisorRecord::factory()->linkedTo($user)->create();

    $user->forceDelete();

    expect($advisor->refresh()->user_id)->toBeNull();
});
