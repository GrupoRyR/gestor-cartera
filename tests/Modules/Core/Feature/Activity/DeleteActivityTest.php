<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use Tests\Modules\Core\Support\ActivityRecords;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->manager = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX);
    $this->client = CarteraRecords::client('Agro Arango');
    $this->activity = ActivityRecords::entry($this->client, 'Llamada a tesorería', '2026-10-15 09:00:00', $this->manager, [
        'type' => ActivityType::Call,
    ]);
});

it('lets portfolio managers and analysts remove an entry, audited', function (Role $role) {
    $user = CoreUsers::create($role, CompanyCode::SGX);

    $this->actingAs($user)
        ->from('/cartera/clientes/'.$this->client->id)
        ->delete(route('core.gestiones.destroy', $this->activity))
        ->assertRedirect('/cartera/clientes/'.$this->client->id)
        ->assertSessionHas('status', 'Eliminamos la gestión «Llamada a tesorería».');

    [$deleted] = TeamUsers::audits('core.activity.deleted');

    expect(ActivityRecord::withTrashed()->findOrFail($this->activity->id)->trashed())->toBeTrue()
        ->and(ActivityRecord::query()->find($this->activity->id))->toBeNull()
        ->and(ActivityRecords::logger()->forClient($this->client->id))->toBe([])
        ->and($deleted->subject_type)->toBe('activity')
        ->and($deleted->subject_id)->toBe((string) $this->activity->id)
        ->and($deleted->user_id)->toBe($user->id)
        ->and($deleted->changes)->toEqual(['type' => 'llamada', 'summary' => 'Llamada a tesorería', 'client' => 'Agro Arango'])
        ->and($deleted->context)->toEqual(['client_id' => $this->client->id]);
})->with([Role::PortfolioManager, Role::PortfolioAnalyst]);

it('forbids every other role', function (Role $role) {
    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX))
        ->delete(route('core.gestiones.destroy', $this->activity))
        ->assertForbidden();

    expect(ActivityRecord::query()->find($this->activity->id))->not->toBeNull()
        ->and(TeamUsers::audits('core.activity.deleted'))->toBe([]);
})->with([Role::Admin, Role::SalesAdvisor, Role::SalesManager, Role::Treasury]);

it('answers 404 for an entry outside the scope of the user', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioManager, CompanyCode::SDC))
        ->delete(route('core.gestiones.destroy', $this->activity))
        ->assertNotFound();

    expect(ActivityRecord::query()->find($this->activity->id))->not->toBeNull()
        ->and(TeamUsers::audits('core.activity.deleted'))->toBe([]);
});

it('answers 404 for an entry that is missing, already removed or of a deleted client', function () {
    $removed = ActivityRecords::entry($this->client, 'Eliminada', '2026-10-15 10:00:00');
    $removed->delete();
    $this->actingAs($this->manager);

    $this->delete(route('core.gestiones.destroy', 999))->assertNotFound();
    $this->delete(route('core.gestiones.destroy', $removed->id))->assertNotFound();

    $this->client->delete();
    $this->delete(route('core.gestiones.destroy', $this->activity))->assertNotFound();

    expect(TeamUsers::audits('core.activity.deleted'))->toBe([]);
});

it('moves the last activity of the client back to the latest entry left', function () {
    $this->client->update(['last_activity_at' => CarteraRecords::at('2026-10-15 11:00:00')]);
    $latest = ActivityRecords::entry($this->client, 'Nota', '2026-10-15 11:00:00', $this->manager);
    ActivityRecords::entry($this->client, 'Correo automático', '2026-10-15 10:30:00');
    $this->actingAs($this->manager);

    $this->delete(route('core.gestiones.destroy', $latest));

    expect($this->client->refresh()->last_activity_at?->toDateTimeString())->toBe('2026-10-15 09:00:00');

    $this->delete(route('core.gestiones.destroy', $this->activity));

    expect($this->client->refresh()->last_activity_at)->toBeNull();
});

it('sends guests to the login page', function () {
    $this->delete(route('core.gestiones.destroy', $this->activity))->assertRedirect('/login');

    expect(ActivityRecord::query()->find($this->activity->id))->not->toBeNull();
});
