<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Tests\Modules\Core\Support\CoreUsers;

/*
| Switching the active company changes which clients the user sees, so it
| goes to the audit log with the company before and after (audit S-13).
| The group view is "grupo", as in the selector.
*/

it('audits the switch with the company before and after', function (string $to, string $expected) {
    $user = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX, CompanyCode::SDC);

    $this->actingAs($user)->from('/')->post('/empresa', ['empresa' => 'SDC']);
    AuditLogRecord::query()->delete();

    $this->actingAs($user)->from('/')->post('/empresa', ['empresa' => $to])->assertRedirect('/');

    $entry = AuditLogRecord::query()->sole();

    expect($entry->action)->toBe('core.company.switched')
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->changes)->toEqual(['company' => ['old' => 'SDC', 'new' => $expected]]);
})->with([
    'to another company' => ['SGX', 'SGX'],
    'to the group view' => ['grupo', 'grupo'],
]);

it('audits nothing when the company does not change', function () {
    $user = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC);

    $this->actingAs($user)->from('/')->post('/empresa', ['empresa' => 'SDC']);
    AuditLogRecord::query()->delete();

    $this->actingAs($user)->from('/')->post('/empresa', ['empresa' => 'SDC']);

    expect(AuditLogRecord::query()->where('action', 'core.company.switched')->exists())->toBeFalse();
});

it('audits nothing when the company is not available', function () {
    $user = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);

    $this->actingAs($user)->from('/')->post('/empresa', ['empresa' => 'SDC'])
        ->assertSessionHas('error');

    expect(AuditLogRecord::query()->where('action', 'core.company.switched')->exists())->toBeFalse();
});
