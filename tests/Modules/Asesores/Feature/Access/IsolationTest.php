<?php

use App\Models\User;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Spreadsheet;
use Tests\Modules\Core\Support\ActivityRecords;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * The proof that an advisor cannot read the portfolio of another, by URL or by
 * export. Every other advisor has a "canary" client: a name, a code, an
 * amount, a commitment note and a log entry that appear nowhere else. A canary
 * showing up in any page or file of somebody else is a leak.
 *
 * The suite runs against Core's real repository and scope, with the real
 * database, not with fakes: it tests the whole path a request takes.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();

    // Bruno (SGX-20): the canary of the advisor next door.
    $this->brunoCanary = asesoresCanary($this->world, 'Canario de Bruno SAS', CompanyCode::SGX, 20, $this->world->bruno, 7_654_321, 4_567_891);
    // The same SAP code as Ana (12), but in the other company.
    $this->sdcCanary = asesoresCanary($this->world, 'Canario de SDC SAS', CompanyCode::SDC, 12, null, 6_543_210, 3_456_789);
    // Ana (SGX-12): the canary the others must never see.
    $this->anaCanary = asesoresCanary($this->world, 'Canario de Ana SAS', CompanyCode::SGX, 12, $this->world->ana, 5_432_109, 2_345_678);
});

/**
 * A client with an amount nobody else has, a commitment, a note in the log,
 * and the strings that would give them away.
 *
 * @return array{client: ClientRecord, commitment: CommitmentRecord, secrets: list<string>}
 */
function asesoresCanary(AdvisorWorld $world, string $name, CompanyCode $company, int $advisor, ?User $author, int $balance, int $promised): array
{
    $client = $world->client($name, $company, $advisor, [[number_format($balance, 2, '.', ''), 120]], ['last_activity_at' => '2026-10-14 09:30:00']);
    $key = strtoupper(preg_replace('/\W+/', '-', $name));
    $author ??= $world->mariaPaula;
    $commitment = CommitmentRecords::commitment($client, $author, '2026-10-30', number_format($promised, 2, '.', ''), ['notes' => "NOTA-{$key}"]);
    ActivityRecords::entry($client, "GESTION-{$key}", '2026-10-14 09:30', $author, ['details' => "DETALLE-{$key}"]);

    return [
        'client' => $client,
        'commitment' => $commitment,
        'secrets' => [$name, $client->code, (string) $client->tax_id, Money::format($balance), Money::format($promised), "NOTA-{$key}", "GESTION-{$key}", "DETALLE-{$key}"],
    ];
}

/**
 * Everything a response says, pages and files alike, as text to search.
 */
function asesoresEverythingIn(TestResponse $response): string
{
    if ($response->baseResponse instanceof BinaryFileResponse) {
        return (string) json_encode(Spreadsheet::of($response), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    return html_entity_decode($response->getContent() ?: '', ENT_QUOTES | ENT_HTML5);
}

/**
 * What of $secrets shows up in $content, as readable lines for the failure
 * report ("asesores.agenda {"asesor":"SGX-20"} leaks Canario SAS").
 *
 * A page echoes back the search the person typed (in the box, in the links),
 * so a secret that is the query itself, such as a code typed in the search,
 * is the person's own text and not a leak; every other secret still counts.
 *
 * @param  array<string, string>  $query
 * @param  list<string>  $secrets
 * @return list<string>
 */
function asesoresLeaksIn(string $route, array $query, string $content, array $secrets): array
{
    $leaks = [];

    foreach ($secrets as $secret) {
        if (in_array($secret, $query, true)) {
            continue;
        }

        if (str_contains($content, $secret)) {
            $leaks[] = "{$route} ".json_encode($query)." leaks {$secret}";
        }
    }

    return $leaks;
}

/**
 * The same page asked in every way a person could tamper with the URL.
 *
 * @return list<array<string, string>>
 */
function asesoresTamperedQueries(array $canary): array
{
    return [
        [],
        ['asesor' => 'SGX-20'],
        ['asesor' => 'SGX-12'],
        ['asesor' => 'SDC-12'],
        ['asesor' => 'SGX-30'],
        ['buscar' => 'Canario'],
        ['buscar' => $canary['client']->code],
        ['buscar' => (string) $canary['client']->tax_id],
        ['estado' => 'vencida', 'vencidos' => '1', 'sort' => 'balance', 'direction' => 'desc'],
        ['page' => '2'],
        ['dias' => '365'],
    ];
}

/**
 * Pages and files that take no id: the ones a URL can only tamper with through the query string.
 */
const ASESORES_LISTING_ROUTES = ['asesores.inicio', 'asesores.clientes.index', 'asesores.historico', 'asesores.agenda', 'asesores.clientes.exportar'];

it('never shows an advisor anything of another advisor in any page or file, whatever the query says', function (string $viewer, string $other) {
    $viewer = $this->world->{$viewer};
    $secrets = array_merge(...array_map(fn (string $key): array => $this->{$key}['secrets'], [$other, 'sdcCanary']));

    $leaks = [];

    foreach (ASESORES_LISTING_ROUTES as $route) {
        foreach (asesoresTamperedQueries($this->{$other}) as $query) {
            $response = $this->actingAs($viewer)->get(route($route, $query))->assertOk();

            $leaks = [...$leaks, ...asesoresLeaksIn($route, $query, asesoresEverythingIn($response), $secrets)];
        }
    }

    expect($leaks)->toBe([]);
})->with([
    'Ana looking at the canary of Bruno' => ['ana', 'brunoCanary'],
    'Bruno looking at the canary of Ana' => ['bruno', 'anaCanary'],
]);

it('shows each advisor their own canary, so the check above is not empty', function () {
    $own = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'));
    $theirs = $this->actingAs($this->world->bruno)->get(route('asesores.clientes.index'));

    expect(asesoresEverythingIn($own))->toContain('Canario de Ana SAS')->not->toContain('Canario de Bruno SAS')
        ->and(asesoresEverythingIn($theirs))->toContain('Canario de Bruno SAS')->not->toContain('Canario de Ana SAS');
});

it('would notice a leak: with the scope switched off, the canary of another advisor does show up', function () {
    // The control of the checks above, which are only worth something if they can fail.
    $this->app->extend(CarteraRepository::class, fn (CarteraRepository $cartera): CarteraRepository => $cartera->asSystem());

    $list = asesoresEverythingIn($this->actingAs($this->world->ana)->get(route('asesores.clientes.index')));
    $file = asesoresEverythingIn($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar')));
    $page = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->brunoCanary['client']->id));

    expect($list)->toContain('Canario de Bruno SAS')
        ->and($file)->toContain('Canario de Bruno SAS')
        ->and($page->status())->toBe(200);
});

it('answers 404, and writes nothing, for every URL that names a client of another advisor', function (string $owner, string $viewer) {
    $client = $this->{$owner}['client']->id;
    $commitment = $this->{$owner}['commitment']->id;
    $viewer = $this->world->{$viewer};
    $before = [CommitmentRecord::query()->count(), ActivityRecord::query()->count()];

    $this->actingAs($viewer)->get(route('asesores.clientes.show', $client))->assertNotFound();
    $this->actingAs($viewer)->get(route('asesores.clientes.exportar_cliente', $client))->assertNotFound();
    $this->actingAs($viewer)->get(route('asesores.clientes.estado_cuenta', $client))->assertNotFound();
    $this->actingAs($viewer)->post(route('asesores.compromisos.store', $client), ['fecha' => '2026-10-30', 'valor' => '1000000'])->assertNotFound();
    $this->actingAs($viewer)->post(route('asesores.notas.store', $client), ['nota' => 'Nota intrusa'])->assertNotFound();

    $this->actingAs($viewer)->get(route('asesores.compromisos.show', $commitment))->assertNotFound();
    $this->actingAs($viewer)->put(route('asesores.compromisos.update', $commitment), ['fecha' => '2026-11-30', 'valor' => '1'])->assertNotFound();
    $this->actingAs($viewer)->post(route('asesores.compromisos.fulfill', $commitment))->assertNotFound();
    $this->actingAs($viewer)->post(route('asesores.compromisos.cancel', $commitment), ['motivo' => 'Intruso'])->assertNotFound();

    expect([CommitmentRecord::query()->count(), ActivityRecord::query()->count()])->toBe($before)
        ->and(CommitmentRecord::query()->findOrFail($commitment)->status)->toBe(CommitmentStatus::Pending);
})->with([
    'Ana on the client of Bruno' => ['brunoCanary', 'ana'],
    'Bruno on the client of Ana' => ['anaCanary', 'bruno'],
    'Ana on the client of the other company with her code' => ['sdcCanary', 'ana'],
]);

it('answers 404 with the same body for a client of another advisor and for one that does not exist', function () {
    $other = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->brunoCanary['client']->id));
    $nobody = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', 987654));

    expect($other->getContent())->toBe($nobody->getContent());
});

it('keeps a sales manager to the team: the clients of advisors outside it do not exist', function () {
    foreach (['anaCanary', 'brunoCanary', 'sdcCanary'] as $canary) {
        $client = $this->{$canary}['client']->id;

        $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.clientes.show', $client))->assertNotFound();
        $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.clientes.exportar_cliente', $client))->assertNotFound();
        $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.compromisos.show', $this->{$canary}['commitment']->id))->assertNotFound();
    }

    $secrets = [...$this->anaCanary['secrets'], ...$this->brunoCanary['secrets'], ...$this->sdcCanary['secrets']];
    $leaks = [];

    foreach (ASESORES_LISTING_ROUTES as $route) {
        foreach (asesoresTamperedQueries($this->anaCanary) as $query) {
            $content = asesoresEverythingIn($this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route($route, $query))->assertOk());

            $leaks = [...$leaks, ...asesoresLeaksIn($route, $query, $content, $secrets)];
        }
    }

    expect($leaks)->toBe([]);
});

it('shows a sales manager the clients of the team, so the check above is not empty', function () {
    $team = asesoresCanary($this->world, 'Canario del equipo SAS', CompanyCode::SGX, 30, null, 1_111_111, 222_222);

    $response = $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.clientes.index'));

    expect(asesoresEverythingIn($response))->toContain('Canario del equipo SAS');
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $team['client']->id))->assertOk();
});

it('closes the viewer to a user who was deactivated, even with the session open', function () {
    $this->world->ana->update(['is_active' => false]);

    $this->actingAs($this->world->ana)->get(route('asesores.clientes.index'))->assertRedirect();
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar'))->assertRedirect();
});

it('shows a user with no linked advisor an empty portfolio, not everybody’s', function () {
    $stranger = TeamUsers::member('Sara Sin Asesor', 'sara.sin.asesor@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);

    $secrets = [...$this->anaCanary['secrets'], ...$this->brunoCanary['secrets']];
    $leaks = [];

    foreach (ASESORES_LISTING_ROUTES as $route) {
        $content = asesoresEverythingIn($this->actingAs($stranger)->get(route($route))->assertOk());

        $leaks = [...$leaks, ...asesoresLeaksIn($route, [], $content, $secrets)];
    }

    expect($leaks)->toBe([]);

    $this->actingAs($stranger)->get(route('asesores.clientes.show', $this->anaCanary['client']->id))->assertNotFound();
});

it('stays closed to roles without the viewer and to guests, on every route', function () {
    $routes = collect(Route::getRoutes()->getRoutesByName())->filter(fn ($route, string $name): bool => str_starts_with($name, 'asesores.'));

    foreach ($routes as $name => $route) {
        $uri = '/'.ltrim(preg_replace('/\{[^}]+\}/', '1', $route->uri()), '/');
        $method = $route->methods()[0];

        $this->call($method, $uri)->assertRedirect(route('core.login'));
        $this->actingAs($this->world->mariaPaula)->call($method, $uri)->assertForbidden();
        // Back to a guest for the next route.
        $this->app['auth']->forgetGuards();
    }

    expect($routes)->not->toBeEmpty();
});

it('has a check here for every route of the module: a new one must be added to this suite', function () {
    $covered = [
        // Listings and files, checked with tampered queries.
        ...ASESORES_LISTING_ROUTES,
        // Named by id, checked to answer 404 for what is not theirs.
        'asesores.clientes.show', 'asesores.clientes.exportar_cliente', 'asesores.clientes.estado_cuenta',
        'asesores.compromisos.store', 'asesores.notas.store',
        'asesores.compromisos.show', 'asesores.compromisos.update', 'asesores.compromisos.fulfill', 'asesores.compromisos.cancel',
        // Sales managers only, by permission (see the team tests).
        'asesores.equipo.index',
    ];

    $routes = collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name): bool => str_starts_with($name, 'asesores.'))->values()->all();

    $this->assertSame([], array_values(array_diff($routes, $covered)), 'These routes are not in the isolation suite.');
    $this->assertSame([], array_values(array_diff($covered, $routes)), 'The suite names routes that no longer exist.');
});
