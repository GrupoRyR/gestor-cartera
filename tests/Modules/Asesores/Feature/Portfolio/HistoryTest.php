<?php

use App\Modules\Asesores\Domain\Format\Percent;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
});

it('lists one row per cutoff of the advisor, the latest first', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.historico'))->assertOk();

    expect(Page::column($response, 1))->toBe(['15/10/2026', '08/10/2026', '01/10/2026'])
        ->and(Page::column($response, 2))->toBe([Money::format(8_500_000), Money::format(4_500_000), Money::format(4_000_000)])
        ->and(Page::column($response, 3))->toBe([Money::format(5_000_000), Money::format(4_500_000), Money::format(4_000_000)])
        ->and(Page::column($response, 4))->toBe([Percent::format(58.8), Percent::format(100), Percent::format(100)])
        ->and(Page::column($response, 5))->toBe([Money::format(5_000_000), Money::format(4_500_000), Money::format(0)]);
});

it('never mixes the history of another advisor in', function () {
    // On 1 October Bodega Bedoya (Bruno) owed 2,5M: Ana's first row is only her 4M.
    $ana = $this->actingAs($this->world->ana)->get(route('asesores.historico'));
    $bruno = $this->actingAs($this->world->bruno)->get(route('asesores.historico'));

    expect(Page::column($ana, 3))->not->toContain(Money::format(6_500_000))
        ->and(Page::column($bruno, 3))->toContain(Money::format(3_000_000), Money::format(2_500_000))
        ->and(Page::column($bruno, 3))->not->toContain(Money::format(5_000_000));
});

it('offers the periods and keeps the one chosen', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.historico', ['dias' => 30]))->assertOk();
    $tabs = Page::html($response)->all('//nav[@aria-label="Periodo del histórico"]//a');

    expect(array_map(fn ($tab) => trim($tab->textContent), $tabs))->toBe([
        'Últimos 30 días', 'Últimos 60 días', 'Últimos 90 días', 'Últimos 180 días', 'Últimos 365 días',
    ])
        ->and(Page::html($response)->text('//nav[@aria-label="Periodo del histórico"]//a[@aria-current="page"]'))->toBe('Últimos 30 días');
});

it('reaches further back when the period is longer', function () {
    $june = CarteraRecords::snapshot(CompanyCode::SGX, '2026-06-01');
    CarteraRecords::invoice($june, $this->world->clients['agro'], '3000000.00', 80);

    $default = $this->actingAs($this->world->ana)->get(route('asesores.historico'));
    $long = $this->actingAs($this->world->ana)->get(route('asesores.historico', ['dias' => 180]));
    $unknown = $this->actingAs($this->world->ana)->get(route('asesores.historico', ['dias' => 45]));

    expect(Page::column($default, 1))->toHaveCount(3)
        ->and(Page::column($long, 1))->toHaveCount(4)
        ->and(Page::column($unknown, 1))->toHaveCount(3);
});

it('lets a sales manager read the history of one advisor of the team', function () {
    $response = $this->actingAs($this->world->gloria)
        ->withSession(['core.company' => 'group'])
        ->get(route('asesores.historico', ['asesor' => 'SDC-50']))
        ->assertOk();

    expect(Page::column($response, 2))->toBe([Money::format(2_500_000)]);
});

it('shows nothing of another advisor when the URL names them', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.historico', ['asesor' => 'SGX-20']))->assertOk();

    expect(Page::column($response, 3))->not->toContain(Money::format(3_000_000), Money::format(2_500_000));
});

it('says so when the period has no cutoff', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX))
        ->get(route('asesores.historico'))
        ->assertOk()
        ->assertSeeText('Todavía no hay cortes en este periodo');
});

it('is closed to guests and to the roles that have their own screens', function () {
    $this->get(route('asesores.historico'))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.historico'))->assertForbidden();
});
