<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CoreUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
});

it('shows an advisor the figures of their own portfolio', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.inicio'))->assertOk();

    expect(Page::stat($response, 'Saldo total'))->toBe(Money::format(8_500_000))
        ->and(Page::stat($response, 'Sin vencer'))->toBe(Money::format(3_500_000))
        ->and(Page::stat($response, 'Vencido'))->toBe(Money::format(5_000_000))
        ->and(Page::stat($response, 'Más de 90 días'))->toBe(Money::format(5_000_000))
        ->and(Page::stat($response, 'Clientes con saldo'))->toBe('2')
        ->and(Page::stat($response, 'Clientes con vencido'))->toBe('1');
    $response->assertSeeText('Mi cartera');
});

it('shows each advisor only their own figures', function () {
    $response = $this->actingAs($this->world->bruno)->get(route('asesores.inicio'))->assertOk();

    expect(Page::stat($response, 'Saldo total'))->toBe(Money::format(3_000_000))
        ->and(Page::stat($response, 'Vencido'))->toBe(Money::format(3_000_000))
        ->and(Page::stat($response, 'Más de 90 días'))->toBe(Money::format(0))
        ->and(Page::stat($response, 'Clientes con saldo'))->toBe('1');
});

it('lists the clients overdue by more than 90 days as the priority', function () {
    $ana = $this->actingAs($this->world->ana)->get(route('asesores.inicio'));
    $bruno = $this->actingAs($this->world->bruno)->get(route('asesores.inicio'));

    expect(Page::listedUnder($ana, 'Atención prioritaria'))->toBe(['Agro Arango'])
        ->and(Page::listedUnder($bruno, 'Atención prioritaria'))->toBe([]);
    $bruno->assertSeeText('Ningún cliente tiene facturas vencidas hace más de 90 días.');
});

it('draws the trend of the overdue balance of the advisor alone', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.inicio'))->assertOk();

    expect(Page::html($response)->count('//svg[@role="img"]'))->toBe(1);
    // 4M at the first cut, 5M at the last: Bruno's 2,5M of 1 October is not in it.
    $response->assertSeeText('Saldo vencido en 3 cortes, del 01/10/2026 al 15/10/2026.')
        ->assertSeeText('Subió '.Money::format(1_000_000).' desde el primer corte.');
});

it('shows a sales manager the portfolio of their team', function () {
    $response = $this->actingAs($this->world->gloria)
        ->withSession(['core.company' => 'group'])
        ->get(route('asesores.inicio'))
        ->assertOk();

    // Comercial Castaño (SGX-30), Jardines Jaramillo (SDC-40) and Granos Giraldo (SDC-50).
    expect(Page::stat($response, 'Saldo total'))->toBe(Money::format(4_100_000))
        ->and(Page::stat($response, 'Vencido'))->toBe(Money::format(3_500_000))
        ->and(Page::stat($response, 'Clientes con saldo'))->toBe('3');
    $response->assertSeeText('Cartera de mi equipo');
});

it('lets a sales manager narrow the dashboard to one advisor of the team', function () {
    $response = $this->actingAs($this->world->gloria)
        ->withSession(['core.company' => 'group'])
        ->get(route('asesores.inicio', ['asesor' => 'SDC-50']))
        ->assertOk();

    expect(Page::stat($response, 'Saldo total'))->toBe(Money::format(2_500_000))
        ->and(Page::stat($response, 'Clientes con saldo'))->toBe('1');
    $response->assertSeeText('Cartera de Laura Vélez');
});

it('offers the advisor filter only to who can look at several advisors', function () {
    $manager = $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.inicio'));
    $advisor = $this->actingAs($this->world->ana)->get(route('asesores.inicio'));

    expect(Page::html($manager)->count('//form[@data-part="advisor-filter"]//select[@name="asesor"]'))->toBe(1)
        ->and(Page::html($advisor)->count('//form[@data-part="advisor-filter"]'))->toBe(0);
});

it('shows nothing of another advisor when the URL asks for them', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.inicio', ['asesor' => 'SGX-20']))->assertOk();

    expect(Page::stat($response, 'Saldo total'))->toBe(Money::format(0))
        ->and(Page::stat($response, 'Clientes con saldo'))->toBe('0');
    $response->assertSeeText('Mi cartera')->assertDontSeeText('Diana Duque')->assertDontSeeText('Bodega Bedoya');
});

it('ignores filters it cannot read', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.inicio', ['asesor' => "SGX-12'; DROP TABLE clients;--"]))->assertOk();

    expect(Page::stat($response, 'Saldo total'))->toBe(Money::format(8_500_000));
});

it('warns when the cutoff is older than two days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'America/Bogota'));

    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Es posible que esta cartera esté desactualizada')
        ->assertSeeText('El último corte es del 15/10/2026, hace 5 días.');
});

it('only names the cutoff while it is recent', function () {
    $this->actingAs($this->world->ana)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Cartera al corte del 15/10/2026.')
        ->assertDontSeeText('desactualizada');
});

it('explains what to do when there is no portfolio yet', function () {
    $newcomer = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);

    $this->actingAs($newcomer)->get(route('asesores.inicio'))
        ->assertOk()
        ->assertSeeText('Aún no hay cartera para mostrar')
        ->assertSeeText('pide a TI que vincule tu usuario con tu código de asesor');
});

it('sends guests to sign in and keeps out the roles that have their own screens', function () {
    $this->get(route('asesores.inicio'))->assertRedirect(route('core.login'));

    $this->actingAs($this->world->mariaPaula)->get(route('asesores.inicio'))->assertForbidden();
});
