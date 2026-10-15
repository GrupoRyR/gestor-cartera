<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin('Laura Admin', 'laura.admin@segurex.com');

    TeamUsers::member('Ana Activa', 'ana.activa@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX, CompanyCode::SDC]);
    TeamUsers::member('Bruno Pendiente', 'bruno.pendiente@derivados.co', null);
    TeamUsers::member('Carla Inactiva', 'carla.inactiva@derivados.co', Role::SalesAdvisor, [CompanyCode::SDC], active: false);
    TeamUsers::member('Diego Inactivo', 'diego.inactivo@segurex.com', null, active: false);
    TeamUsers::member('Elena Eliminada', 'elena.eliminada@segurex.com', Role::SalesManager, [CompanyCode::SGX], deleted: true);
});

it('lists each state in its own tab, with the count of every tab', function (?string $tab, array $names) {
    $response = $this->actingAs($this->admin)
        ->get(route('core.equipo.index', array_filter(['estado' => $tab])))
        ->assertOk();

    expect(TeamUsers::listedNames($response))->toBe($names)
        ->and(TeamUsers::tabCounts($response))->toBe([
            'Activos' => 2,
            'Sin rol' => 1,
            'Inactivos' => 2,
            'Eliminados' => 1,
        ])
        ->and(Html::clean(TeamUsers::html($response)->one('//nav[@aria-label="Estado de los usuarios"]//a[@aria-current="page"]')->textContent))
        ->toStartWith(match ($tab) {
            'sin-rol' => 'Sin rol',
            'inactivos' => 'Inactivos',
            'eliminados' => 'Eliminados',
            default => 'Activos',
        });
})->with([
    'active by default' => [null, ['Ana Activa', 'Laura Admin']],
    'active' => ['activos', ['Ana Activa', 'Laura Admin']],
    'without a role' => ['sin-rol', ['Bruno Pendiente']],
    'inactive' => ['inactivos', ['Carla Inactiva', 'Diego Inactivo']],
    'deleted' => ['eliminados', ['Elena Eliminada']],
    'unknown tab' => ['otra', ['Ana Activa', 'Laura Admin']],
]);

it('shows the role, the companies and the state of each user', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index'))->assertOk());
    $row = fn (string $name): array => array_map(
        fn ($cell): string => Html::clean($cell->textContent),
        $html->all("//table/tbody/tr[td[1][normalize-space(.)='{$name}']]/td"),
    );

    expect(array_slice($row('Ana Activa'), 0, 5))->toBe(['Ana Activa', 'ana.activa@segurex.com', 'Analista de cartera', 'SGX SDC', 'Activo'])
        ->and(array_slice($row('Laura Admin'), 2, 3))->toBe(['Administrador', 'Todas', 'Activo']);
});

it('labels users without a role, inactive users and deleted users', function () {
    $pending = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index', ['estado' => 'sin-rol'])));
    $inactive = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index', ['estado' => 'inactivos'])));
    $deleted = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index', ['estado' => 'eliminados'])));

    expect($pending->text('//table/tbody/tr/td[3]'))->toBe('Sin rol')
        ->and($inactive->text("//table/tbody/tr[td[1][normalize-space(.)='Carla Inactiva']]/td[5]"))->toBe('Inactivo')
        ->and($deleted->text('//table/tbody/tr/td[5]'))->toBe('Eliminado');
});

it('searches by name or e-mail within the tab', function (string $tab, string $search, array $names) {
    $response = $this->actingAs($this->admin)
        ->get(route('core.equipo.index', ['estado' => $tab, 'buscar' => $search]))
        ->assertOk();

    expect(TeamUsers::listedNames($response))->toBe($names)
        ->and(TeamUsers::html($response)->one('//input[@name="buscar"]')->getAttribute('value'))->toBe($search);
})->with([
    'part of a name, any case' => ['activos', 'activa', ['Ana Activa']],
    'part of an e-mail' => ['inactivos', '@derivados.co', ['Carla Inactiva']],
    'a name in another tab' => ['activos', 'Pendiente', []],
]);

it('counts the matches of the search in every tab', function () {
    $response = $this->actingAs($this->admin)->get(route('core.equipo.index', ['buscar' => 'derivados']));

    expect(TeamUsers::tabCounts($response))->toBe([
        'Activos' => 0,
        'Sin rol' => 1,
        'Inactivos' => 1,
        'Eliminados' => 0,
    ]);
});

it('sorts by name or e-mail in both directions', function (array $query, array $names) {
    $response = $this->actingAs($this->admin)
        ->get(route('core.equipo.index', ['estado' => 'inactivos', ...$query]))
        ->assertOk();

    expect(TeamUsers::listedNames($response))->toBe($names);
})->with([
    'name, descending' => [['sort' => 'name', 'direction' => 'desc'], ['Diego Inactivo', 'Carla Inactiva']],
    'e-mail, ascending' => [['sort' => 'email', 'direction' => 'asc'], ['Carla Inactiva', 'Diego Inactivo']],
    'e-mail, descending' => [['sort' => 'email', 'direction' => 'desc'], ['Diego Inactivo', 'Carla Inactiva']],
    'unknown column falls back to the name' => [['sort' => 'password', 'direction' => 'sideways'], ['Carla Inactiva', 'Diego Inactivo']],
]);

it('marks the sorted column', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index', ['sort' => 'name', 'direction' => 'desc'])));

    expect($html->one('//th[@aria-sort]')->getAttribute('aria-sort'))->toBe('descending')
        ->and(Html::clean($html->one('//th[@aria-sort]')->textContent))->toContain('Nombre');
});

it('shows 25 users per page and keeps the search and the order in the page links', function () {
    foreach (range(1, 27) as $number) {
        TeamUsers::member(sprintf('Usuario %02d', $number), sprintf('usuario%02d@segurex.com', $number), Role::PortfolioAnalyst, [CompanyCode::SGX]);
    }

    $query = ['buscar' => 'Usuario', 'sort' => 'name', 'direction' => 'desc'];
    $first = $this->actingAs($this->admin)->get(route('core.equipo.index', $query))->assertOk();
    $next = TeamUsers::html($first)->one('//nav[@aria-label="Paginación"]//a[@rel="next"]')->getAttribute('href');

    parse_str((string) parse_url($next, PHP_URL_QUERY), $nextQuery);

    expect(TeamUsers::listedNames($first))->toHaveCount(25)
        ->and(TeamUsers::listedNames($first)[0])->toBe('Usuario 27')
        ->and($nextQuery)->toMatchArray([...$query, 'page' => '2'])
        ->and(TeamUsers::listedNames($this->actingAs($this->admin)->get($next)))->toBe(['Usuario 02', 'Usuario 01']);
});

it('shows an empty state when nothing matches', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index', ['buscar' => 'nadie con este nombre']))->assertOk());

    expect($html->count('//table'))->toBe(0)
        ->and($html->text('//main//*[@data-part="title"]'))->toBe('No encontramos usuarios con «nadie con este nombre»');
});

it('explains an empty tab', function () {
    User::onlyTrashed()->restore();

    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.equipo.index', ['estado' => 'eliminados']))->assertOk());

    expect($html->count('//table'))->toBe(0)
        ->and($html->text('//main//*[@data-part="title"]'))->toBe('No hay usuarios eliminados');
});
