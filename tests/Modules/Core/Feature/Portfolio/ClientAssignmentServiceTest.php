<?php

use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Portfolio\EloquentClientAssignmentService;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Modules\Core\Support\ActivityRecords;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * María Paula Rojas manages the portfolio of both companies; Juan Pablo Ortiz
 * is an analyst of Segurex only.
 */
beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->maria = TeamUsers::member('María Paula Rojas', 'mariapaula.rojas@segurex.com', Role::PortfolioManager, [CompanyCode::SGX, CompanyCode::SDC]);
    $this->juan = TeamUsers::member('Juan Pablo Ortiz', 'juan.ortiz@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX]);
    $this->service = fn (): ClientAssignmentService => app(ClientAssignmentService::class);
    $this->managerOf = fn (ClientRecord $client): ?int => $client->refresh()->manager_user_id;
});

it('is the ClientAssignmentService of the container', function () {
    expect(app(ClientAssignmentService::class))->toBeInstanceOf(EloquentClientAssignmentService::class);
});

it('refuses every role without the permission, and guests, before reading anything', function (?Role $role, bool $signedIn) {
    $client = CarteraRecords::client('Agro Arango');

    if ($signedIn) {
        $this->actingAs(CoreUsers::create($role, CompanyCode::SGX));
    }

    expect(fn () => ($this->service)()->assignees())->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->service)()->assign([$client->id], $this->maria->id))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->service)()->assign([999], 999))->toThrow(AuthorizationException::class)
        ->and(fn () => ($this->service)()->assignMatching(new ClientQuery, $this->maria->id))->toThrow(AuthorizationException::class)
        ->and(($this->managerOf)($client))->toBeNull()
        ->and(TeamUsers::audits('core.client.manager_changed'))->toBe([]);
})->with([
    'sales advisor' => [Role::SalesAdvisor, true],
    'sales manager' => [Role::SalesManager, true],
    'treasury' => [Role::Treasury, true],
    'no role' => [null, true],
    'guest' => [null, false],
]);

it('lists the portfolio managers and analysts of the companies available to the user, by name', function () {
    TeamUsers::member('Andrea Bedoya', 'andrea.bedoya@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SDC]);
    TeamUsers::member('Beatriz Cano', 'beatriz.cano@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX], deleted: true);
    TeamUsers::member('Camilo Duque', 'camilo.duque@segurex.com', Role::PortfolioManager, [CompanyCode::SGX], active: false);
    TeamUsers::member('Carlos Pérez', 'carlos.perez@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);
    TeamUsers::member('Diana Ríos', 'diana.rios@segurex.com', Role::Treasury, [CompanyCode::SGX]);
    TeamUsers::member('Elena Gil', 'elena.gil@segurex.com', Role::PortfolioAnalyst);
    TeamUsers::admin();

    $this->actingAs($this->juan);

    expect(($this->service)()->assignees())->toEqual([
        new UserRef($this->juan->id, 'Juan Pablo Ortiz', 'juan.ortiz@segurex.com'),
        new UserRef($this->maria->id, 'María Paula Rojas', 'mariapaula.rojas@segurex.com'),
    ]);

    // An admin works in every company, so Andrea Bedoya (Derivados only) appears too.
    $this->actingAs(TeamUsers::admin('Laura Admin 2', 'laura.admin2@segurex.com'));

    expect(array_map(fn (UserRef $user): string => $user->name, ($this->service)()->assignees()))
        ->toBe(['Andrea Bedoya', 'Juan Pablo Ortiz', 'María Paula Rojas']);
});

it('assigns a manager to the clients that change, logged and audited', function (Role $role) {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $actor = TeamUsers::member('Sara Londoño', 'sara.londono@segurex.com', $role, [CompanyCode::SGX]);
    $this->actingAs($actor);

    $unassigned = CarteraRecords::client('Agro Arango');
    $other = CarteraRecords::client('Bodega Bedoya', ['manager_user_id' => $this->juan->id]);
    $same = CarteraRecords::client('Comercial Castaño', ['manager_user_id' => $this->maria->id]);

    $changed = ($this->service)()->assign([$unassigned->id, $other->id, $same->id, $unassigned->id], $this->maria->id);

    [$assigned] = ActivityRecords::logger()->forClient($unassigned->id);
    [$moved] = ActivityRecords::logger()->forClient($other->id);
    $audits = TeamUsers::audits('core.client.manager_changed');

    expect($changed)->toBe(2)
        ->and(($this->managerOf)($unassigned))->toBe($this->maria->id)
        ->and(($this->managerOf)($other))->toBe($this->maria->id)
        ->and(($this->managerOf)($same))->toBe($this->maria->id)
        ->and($assigned->type)->toBe(ActivityType::System)
        ->and($assigned->summary)->toBe('Gestor asignado: María Paula Rojas')
        ->and($assigned->user)->toEqual(new UserRef($actor->id, 'Sara Londoño', 'sara.londono@segurex.com'))
        ->and($assigned->occurredAt->toDateTimeString())->toBe('2026-10-15 09:30:00')
        ->and($assigned->metadata)->toEqual(['from' => null, 'to' => $this->maria->id])
        ->and($moved->summary)->toBe('Gestor cambiado: de Juan Pablo Ortiz a María Paula Rojas')
        ->and($moved->metadata)->toEqual(['from' => $this->juan->id, 'to' => $this->maria->id])
        ->and(ActivityRecords::logger()->forClient($same->id))->toBe([])
        // Assigning is bookkeeping, not work on the client: the last activity stays.
        ->and($unassigned->refresh()->last_activity_at)->toBeNull()
        ->and($audits)->toHaveCount(2)
        ->and(array_map(fn ($audit): string => $audit->subject_id, $audits))->toBe([(string) $unassigned->id, (string) $other->id])
        ->and($audits[0]->subject_type)->toBe('client')
        ->and($audits[0]->user_id)->toBe($actor->id)
        ->and($audits[0]->changes)->toEqual(['manager_user_id' => ['old' => null, 'new' => $this->maria->id]])
        ->and($audits[1]->changes)->toEqual(['manager_user_id' => ['old' => $this->juan->id, 'new' => $this->maria->id]]);
})->with([Role::PortfolioManager, Role::PortfolioAnalyst, Role::Admin]);

it('leaves clients unassigned with a null manager', function () {
    $this->actingAs($this->juan);
    $client = CarteraRecords::client('Agro Arango', ['manager_user_id' => $this->maria->id]);
    $alreadyUnassigned = CarteraRecords::client('Bodega Bedoya');

    $changed = ($this->service)()->assign([$client->id, $alreadyUnassigned->id], null);

    [$entry] = ActivityRecords::logger()->forClient($client->id);
    [$audit] = TeamUsers::audits('core.client.manager_changed');

    expect($changed)->toBe(1)
        ->and(($this->managerOf)($client))->toBeNull()
        ->and($entry->summary)->toBe('Gestor quitado: María Paula Rojas')
        ->and($entry->metadata)->toEqual(['from' => $this->maria->id, 'to' => null])
        ->and(ActivityRecords::logger()->forClient($alreadyUnassigned->id))->toBe([])
        ->and($audit->changes)->toEqual(['manager_user_id' => ['old' => $this->maria->id, 'new' => null]]);
});

it('writes nothing for an empty list or when every client already has that manager', function () {
    $this->actingAs($this->juan);
    $client = CarteraRecords::client('Agro Arango', ['manager_user_id' => $this->maria->id]);

    expect(($this->service)()->assign([], $this->maria->id))->toBe(0)
        ->and(($this->service)()->assign([$client->id], $this->maria->id))->toBe(0)
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.client.manager_changed'))->toBe([]);
});

it('refuses a client that is missing, deleted or out of reach, and changes none', function () {
    $this->actingAs($this->juan);
    $client = CarteraRecords::client('Agro Arango');
    $deleted = CarteraRecords::client('Bodega Bedoya');
    $deleted->delete();
    $otherCompany = CarteraRecords::client('Comercial Castaño', ['company' => CompanyCode::SDC]);

    expect(fn () => ($this->service)()->assign([$client->id, $deleted->id], $this->juan->id))
        ->toThrow(RecordNotFound::class, "No client with id {$deleted->id} exists in the current scope.")
        ->and(fn () => ($this->service)()->assign([$client->id, $otherCompany->id], $this->juan->id))
        ->toThrow(RecordNotFound::class, "No client with id {$otherCompany->id} exists in the current scope.")
        ->and(fn () => ($this->service)()->assign([999, $client->id], $this->juan->id))
        ->toThrow(RecordNotFound::class, 'No client with id 999 exists in the current scope.')
        ->and(($this->managerOf)($client))->toBeNull()
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.client.manager_changed'))->toBe([]);
});

it('refuses a manager who is not an assignee or cannot work in the company of every client, and changes none', function () {
    $this->actingAs($this->maria);
    $segurex = CarteraRecords::client('Agro Arango');
    $derivados = CarteraRecords::client('Bodega Bedoya', ['company' => CompanyCode::SDC]);
    $advisor = TeamUsers::member('Carlos Pérez', 'carlos.perez@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX, CompanyCode::SDC]);
    $deleted = TeamUsers::member('Beatriz Cano', 'beatriz.cano@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX, CompanyCode::SDC], deleted: true);

    foreach ([$this->juan->id, $advisor->id, $deleted->id, 999] as $managerUserId) {
        expect(fn () => ($this->service)()->assign([$segurex->id, $derivados->id], $managerUserId))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(($this->managerOf)($segurex))->toBeNull()
        ->and(($this->managerOf)($derivados))->toBeNull()
        ->and(ActivityRecord::query()->count())->toBe(0)
        ->and(TeamUsers::audits('core.client.manager_changed'))->toBe([])
        // Juan Pablo works in Segurex, so he may manage its clients.
        ->and(($this->service)()->assign([$segurex->id], $this->juan->id))->toBe(1);
});

it('assigns every client that matches a query in the scope of the user', function () {
    $this->actingAs($this->maria);
    $agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12]);
    $bodega = CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 12, 'manager_user_id' => $this->maria->id]);
    $castano = CarteraRecords::client('Comercial Castaño', ['advisor_code' => 15]);
    $duque = CarteraRecords::client('Distribuciones Duque', ['advisor_code' => 12, 'company' => CompanyCode::SDC]);
    CarteraRecords::switchTo(CompanyCode::SGX);

    $changed = ($this->service)()->assignMatching(new ClientQuery(advisorCode: 12, unassigned: true, sort: 'balance', direction: 'desc'), $this->juan->id);

    expect($changed)->toBe(1)
        ->and(($this->managerOf)($agro))->toBe($this->juan->id)
        ->and(($this->managerOf)($bodega))->toBe($this->maria->id)
        ->and(($this->managerOf)($castano))->toBeNull()
        // Derivados is out of the active company, as in the Cartera list.
        ->and(($this->managerOf)($duque))->toBeNull()
        ->and(TeamUsers::audits('core.client.manager_changed'))->toHaveCount(1);
});

it('checks the manager against the companies of every matching client', function () {
    $this->actingAs($this->maria);
    $agro = CarteraRecords::client('Agro Arango');
    CarteraRecords::client('Bodega Bedoya', ['company' => CompanyCode::SDC]);
    CarteraRecords::switchTo(null);

    expect(fn () => ($this->service)()->assignMatching(new ClientQuery, $this->juan->id))->toThrow(InvalidArgumentException::class)
        ->and(($this->managerOf)($agro))->toBeNull()
        ->and(($this->service)()->assignMatching(new ClientQuery(companies: [CompanyCode::SGX]), $this->juan->id))->toBe(1);
});

it('assigns more clients than fit in one statement', function () {
    $this->actingAs($this->maria);
    $count = EloquentClientAssignmentService::CHUNK + 1;
    ClientRecord::factory()->count($count)->create();

    expect(($this->service)()->assignMatching(new ClientQuery(unassigned: true), $this->juan->id))->toBe($count)
        ->and(ClientRecord::query()->where('manager_user_id', $this->juan->id)->count())->toBe($count)
        ->and(ActivityRecord::query()->where('type', ActivityType::System)->count())->toBe($count)
        ->and(TeamUsers::audits('core.client.manager_changed'))->toHaveCount($count);
});
