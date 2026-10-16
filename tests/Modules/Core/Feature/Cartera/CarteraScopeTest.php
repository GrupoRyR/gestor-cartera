<?php

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Portfolio\EloquentCarteraRepository;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * Who sees which clients (docs/arquitectura.md §6.2). Advisor code 12 exists
 * in both companies: SGX-12 is Ana's, SDC-12 belongs to nobody.
 */
beforeEach(function () {
    $this->ana = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX, CompanyCode::SDC);
    $this->bruno = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
    $this->gloria = CoreUsers::create(Role::SalesManager, CompanyCode::SGX, CompanyCode::SDC);

    TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $this->ana);
    TeamUsers::advisor(CompanyCode::SGX, 20, 'Diana Duque', $this->bruno);
    $sgxTeam = TeamUsers::advisor(CompanyCode::SGX, 30, 'Felipe Ospina');
    TeamUsers::advisor(CompanyCode::SDC, 12, 'Hernán Toro');
    $sdcTeam = TeamUsers::advisor(CompanyCode::SDC, 40, 'Juliana Restrepo');
    TeamUsers::advisor(CompanyCode::SDC, 50, 'Laura Vélez', $this->gloria);
    AdvisorRecord::teamOf($this->gloria)->attach([$sgxTeam->id, $sdcTeam->id]);

    $this->clients = [
        'agro' => CarteraRecords::client('Agro Arango', ['advisor_code' => 12]),
        'bodega' => CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 20]),
        'comercial' => CarteraRecords::client('Comercial Castaño', ['advisor_code' => 30]),
        'distribuciones' => CarteraRecords::client('Distribuciones Duque', ['advisor_code' => null]),
        'equipos' => CarteraRecords::client('Equipos Echeverri', ['company' => CompanyCode::SDC, 'advisor_code' => 12]),
        'ferreteria' => CarteraRecords::client('Ferretería Franco', ['company' => CompanyCode::SDC, 'advisor_code' => 40]),
        'granos' => CarteraRecords::client('Granos Giraldo', ['company' => CompanyCode::SDC, 'advisor_code' => 50]),
    ];

    $sgx = CarteraRecords::snapshot(CompanyCode::SGX);
    $sdc = CarteraRecords::snapshot(CompanyCode::SDC);

    foreach ($this->clients as $client) {
        CarteraRecords::invoice($client->company === CompanyCode::SGX ? $sgx : $sdc, $client, '1000.00', 10);
    }

    // Every client name, in name order.
    $this->everyClient = [
        'Agro Arango', 'Bodega Bedoya', 'Comercial Castaño', 'Distribuciones Duque',
        'Equipos Echeverri', 'Ferretería Franco', 'Granos Giraldo',
    ];
});

it('is the CarteraRepository of the container', function () {
    expect(app(CarteraRepository::class))->toBeInstanceOf(EloquentCarteraRepository::class);
});

it('shows an asesor only the clients of the SAP advisor linked to them', function () {
    $this->actingAs($this->ana);
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe(['Agro Arango'])
        ->and(CarteraRecords::namesOf($cartera->paginateClients(new ClientQuery)->items()))->toBe(['Agro Arango'])
        ->and($cartera->client($this->clients['agro']->id)?->summary->name)->toBe('Agro Arango')
        ->and($cartera->openInvoices($this->clients['agro']->id))->toHaveCount(1)
        ->and($cartera->client($this->clients['bodega']->id))->toBeNull()
        ->and($cartera->openInvoices($this->clients['bodega']->id))->toBe([])
        ->and(CarteraRecords::advisors())->toBe(['SGX-12 Carlos Pérez']);
});

it('never shows an asesor the clients of the same advisor code in the other company', function () {
    $this->actingAs($this->ana);
    CarteraRecords::switchTo(null);
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe(['Agro Arango'])
        ->and($cartera->client($this->clients['equipos']->id))->toBeNull()
        ->and($cartera->openInvoices($this->clients['equipos']->id))->toBe([])
        ->and(CarteraRecords::advisors())->toBe(['SGX-12 Carlos Pérez']);
});

it('shows a sales manager the clients of their team and of the advisors linked to them', function () {
    $this->actingAs($this->gloria);
    CarteraRecords::switchTo(null);
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe(['Comercial Castaño', 'Ferretería Franco', 'Granos Giraldo'])
        ->and($cartera->client($this->clients['agro']->id))->toBeNull()
        ->and($cartera->client($this->clients['granos']->id)?->summary->name)->toBe('Granos Giraldo')
        ->and(CarteraRecords::advisors())->toBe(['SGX-30 Felipe Ospina', 'SDC-40 Juliana Restrepo', 'SDC-50 Laura Vélez']);
});

it('shows every client and advisor to portfolio managers, analysts and admins', function (Role $role) {
    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC));
    CarteraRecords::switchTo(null);
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe($this->everyClient)
        ->and($cartera->client($this->clients['distribuciones']->id)?->summary->name)->toBe('Distribuciones Duque')
        ->and(CarteraRecords::advisors())->toBe([
            'SGX-12 Carlos Pérez', 'SGX-20 Diana Duque', 'SGX-30 Felipe Ospina',
            'SDC-12 Hernán Toro', 'SDC-40 Juliana Restrepo', 'SDC-50 Laura Vélez',
        ]);
})->with([Role::PortfolioAnalyst, Role::PortfolioManager, Role::Admin]);

it('shows nothing to treasury and to users without a role', function (?Role $role) {
    $this->actingAs(CoreUsers::create($role, CompanyCode::SGX, CompanyCode::SDC));
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe([])
        ->and($cartera->paginateClients(new ClientQuery)->total())->toBe(0)
        ->and($cartera->client($this->clients['agro']->id))->toBeNull()
        ->and($cartera->openInvoices($this->clients['agro']->id))->toBe([])
        ->and($cartera->advisors())->toBe([]);
})->with([
    'treasury' => [Role::Treasury],
    'no role' => [null],
]);

it('adds up the clients of every role of a user', function () {
    $this->ana->assignRole(SpatieRole::findOrCreate(Role::Treasury->value, 'web'));
    $this->actingAs($this->ana);

    expect(CarteraRecords::names())->toBe(['Agro Arango']);
});

it('shows nothing to inactive and deleted users', function (bool $active, bool $deleted) {
    $user = TeamUsers::member('Nadia Ríos', 'nadia.rios@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX], $active, $deleted);
    $this->actingAs($user);

    expect(CarteraRecords::names())->toBe([])
        ->and(CarteraRecords::repository()->client($this->clients['agro']->id))->toBeNull()
        ->and(CarteraRecords::repository()->advisors())->toBe([]);
})->with([
    'inactive' => [false, false],
    'deleted' => [true, true],
]);

it('shows nothing to guests', function () {
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe([])
        ->and($cartera->paginateClients(new ClientQuery)->total())->toBe(0)
        ->and($cartera->client($this->clients['agro']->id))->toBeNull()
        ->and($cartera->openInvoices($this->clients['agro']->id))->toBe([])
        ->and($cartera->advisors())->toBe([]);
});

it('gives the system copy every client of every company, or of the companies asked for', function () {
    $system = CarteraRecords::repository()->asSystem();

    expect(CarteraRecords::names(repository: $system))->toBe($this->everyClient)
        ->and(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SDC]), $system))
        ->toBe(['Equipos Echeverri', 'Ferretería Franco', 'Granos Giraldo'])
        ->and($system->client($this->clients['granos']->id)?->summary->name)->toBe('Granos Giraldo')
        ->and($system->openInvoices($this->clients['granos']->id))->toHaveCount(1)
        ->and(CarteraRecords::advisors($system))->toHaveCount(6);
});

it('drops the user scope in the system copy, but not in the original', function () {
    $this->actingAs($this->bruno);
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names(repository: $cartera->asSystem()))->toBe($this->everyClient)
        ->and(CarteraRecords::names(repository: $cartera))->toBe(['Bodega Bedoya']);
});

it('limits the clients to the active company or the group view', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe(['Agro Arango', 'Bodega Bedoya', 'Comercial Castaño', 'Distribuciones Duque'])
        ->and($cartera->client($this->clients['granos']->id))->toBeNull()
        ->and($cartera->openInvoices($this->clients['granos']->id))->toBe([])
        ->and(CarteraRecords::advisors())->toBe(['SGX-12 Carlos Pérez', 'SGX-20 Diana Duque', 'SGX-30 Felipe Ospina']);

    CarteraRecords::switchTo(CompanyCode::SDC);

    expect(CarteraRecords::names())->toBe(['Equipos Echeverri', 'Ferretería Franco', 'Granos Giraldo'])
        ->and($cartera->client($this->clients['granos']->id)?->summary->name)->toBe('Granos Giraldo');

    CarteraRecords::switchTo(null);

    expect(CarteraRecords::names())->toBe($this->everyClient);
});

it('takes the companies of the query, clipped to the ones the user may see', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC));

    expect(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SDC])))
        ->toBe(['Equipos Echeverri', 'Ferretería Franco', 'Granos Giraldo']);

    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX));

    expect(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SDC])))->toBe([])
        ->and(CarteraRecords::names(new ClientQuery(companies: [CompanyCode::SDC, CompanyCode::SGX])))
        ->toBe(['Agro Arango', 'Bodega Bedoya', 'Comercial Castaño', 'Distribuciones Duque']);
});

it('hides soft-deleted clients everywhere', function () {
    $this->actingAs(CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX));
    $this->clients['bodega']->delete();
    $cartera = CarteraRecords::repository();

    expect(CarteraRecords::names())->toBe(['Agro Arango', 'Comercial Castaño', 'Distribuciones Duque'])
        ->and($cartera->paginateClients(new ClientQuery)->total())->toBe(3)
        ->and($cartera->client($this->clients['bodega']->id))->toBeNull()
        ->and($cartera->openInvoices($this->clients['bodega']->id))->toBe([])
        ->and(ClientRecord::withTrashed()->count())->toBe(7);
});
