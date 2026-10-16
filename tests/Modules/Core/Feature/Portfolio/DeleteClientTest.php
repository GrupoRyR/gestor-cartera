<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->manager = CoreUsers::create(Role::PortfolioManager, CompanyCode::SGX);
    $this->client = CarteraRecords::client('Agro Arango', ['code' => 'C900123456']);
    $this->page = '/cartera/clientes/'.$this->client->id;
});

it('lets the portfolio manager remove a client, audited', function () {
    $this->actingAs($this->manager)
        ->from($this->page)
        ->delete(route('core.clientes.destroy', $this->client))
        ->assertRedirect($this->page)
        ->assertSessionHas('status', 'Eliminamos el cliente Agro Arango.');

    [$deleted] = TeamUsers::audits('core.client.deleted');

    expect(ClientRecord::withTrashed()->findOrFail($this->client->id)->trashed())->toBeTrue()
        ->and(CarteraRecords::repository()->client($this->client->id))->toBeNull()
        ->and(CarteraRecords::names())->toBe([])
        ->and($deleted->subject_type)->toBe('client')
        ->and($deleted->subject_id)->toBe((string) $this->client->id)
        ->and($deleted->user_id)->toBe($this->manager->id)
        ->and($deleted->changes)->toEqual(['code' => 'C900123456', 'name' => 'Agro Arango', 'company' => 'SGX'])
        ->and($deleted->context)->toBeNull();
});

it('restores a removed client, audited', function () {
    $this->client->delete();

    $this->actingAs($this->manager)
        ->from($this->page)
        ->post(route('core.clientes.restore', $this->client))
        ->assertRedirect($this->page)
        ->assertSessionHas('status', 'Restauramos el cliente Agro Arango.');

    [$restored] = TeamUsers::audits('core.client.restored');

    expect(ClientRecord::query()->find($this->client->id))->not->toBeNull()
        ->and(CarteraRecords::repository()->client($this->client->id)?->summary->name)->toBe('Agro Arango')
        ->and($restored->subject_id)->toBe((string) $this->client->id)
        ->and($restored->user_id)->toBe($this->manager->id)
        ->and($restored->changes)->toEqual(['code' => 'C900123456', 'name' => 'Agro Arango', 'company' => 'SGX']);
});

it('changes nothing when restoring a client that is not removed', function () {
    $this->actingAs($this->manager)
        ->from($this->page)
        ->post(route('core.clientes.restore', $this->client))
        ->assertRedirect($this->page)
        ->assertSessionHas('status', 'El cliente Agro Arango no estaba eliminado.');

    expect(TeamUsers::audits('core.client.restored'))->toBe([]);
});

it('forbids every other role', function (Role $role) {
    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX));

    $this->delete(route('core.clientes.destroy', $this->client))->assertForbidden();

    $this->client->delete();
    $this->post(route('core.clientes.restore', $this->client))->assertForbidden();

    expect(ClientRecord::withTrashed()->findOrFail($this->client->id)->trashed())->toBeTrue()
        ->and(TeamUsers::audits('core.client.deleted'))->toBe([])
        ->and(TeamUsers::audits('core.client.restored'))->toBe([]);
})->with([Role::PortfolioAnalyst, Role::Admin, Role::SalesAdvisor, Role::SalesManager, Role::Treasury]);

it('answers 404 for a client outside the scope of the manager', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioManager, CompanyCode::SDC));

    $this->delete(route('core.clientes.destroy', $this->client))->assertNotFound();

    $this->client->delete();
    $this->post(route('core.clientes.restore', $this->client))->assertNotFound();

    expect(ClientRecord::withTrashed()->findOrFail($this->client->id)->trashed())->toBeTrue()
        ->and(TeamUsers::audits('core.client.deleted'))->toBe([])
        ->and(TeamUsers::audits('core.client.restored'))->toBe([]);
});

it('answers 404 for a client that does not exist or is already removed', function () {
    $this->actingAs($this->manager);
    $this->client->delete();

    $this->delete(route('core.clientes.destroy', $this->client))->assertNotFound();
    $this->delete(route('core.clientes.destroy', 999))->assertNotFound();
    $this->post(route('core.clientes.restore', 999))->assertNotFound();

    expect(TeamUsers::audits('core.client.deleted'))->toBe([]);
});

it('sends guests to the login page', function () {
    $this->delete(route('core.clientes.destroy', $this->client))->assertRedirect('/login');
    $this->post(route('core.clientes.restore', $this->client))->assertRedirect('/login');

    expect(ClientRecord::query()->find($this->client->id))->not->toBeNull();
});
