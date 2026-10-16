<?php

use App\Modules\Asesores\Domain\Format\Percent;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * Gloria's team: Felipe (SGX-30, Comercial Castaño: 1M, 10 days), Juliana
 * (SDC-40, Jardines Jaramillo: 600k due today) and Laura (SDC-50, Granos
 * Giraldo: 2,5M, 70 days), who is linked to Gloria herself.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->team = fn () => $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.equipo.index'));
});

it('lists the advisors of the team, the most overdue first', function () {
    $response = ($this->team)()->assertOk();

    expect(Page::clientNames($response))->toBe(['Laura Vélez', 'Felipe Ospina', 'Juliana Restrepo'])
        ->and(Page::column($response, 2))->toBe(['1', '1', '1'])
        ->and(Page::column($response, 3))->toBe([Money::format(2_500_000), Money::format(1_000_000), Money::format(600_000)]);
});

it('tells the advisors apart by company and code', function () {
    $response = ($this->team)();

    expect(Page::html($response)->all('//table/tbody/tr/td[1]/span[2]'))->toHaveCount(3);
    $response->assertSeeText('SDC-50')->assertSeeText('SGX-30')->assertSeeText('SDC-40');
});

it('shows what is overdue as an amount and as a share of the advisor’s own balance', function () {
    $response = ($this->team)();

    expect(Page::column($response, 4))->toBe([
        Money::format(2_500_000).' · '.Percent::format(100, 0),
        Money::format(1_000_000).' · '.Percent::format(100, 0),
        Money::format(0).' · '.Percent::format(0, 0),
    ]);
});

it('draws each bar against the biggest overdue of the team', function () {
    $response = ($this->team)();
    $widths = array_map(
        fn ($bar) => preg_match('/width:\s*([\d.]+)%/', $bar->getAttribute('style'), $match) ? (float) $match[1] : null,
        Page::html($response)->all('//table/tbody/tr/td[4]//span[@style]'),
    );

    expect($widths)->toBe([100.0, 40.0, 0.0]);
});

it('counts the commitments each advisor has broken', function () {
    CommitmentRecords::commitment($this->world->clients['granos'], $this->world->gloria, '2026-10-05', '800000.00', ['status' => CommitmentStatus::Broken, 'broken_at' => '2026-10-06 00:15:00']);
    CommitmentRecords::commitment($this->world->clients['granos'], $this->world->gloria, '2026-10-30', '800000.00');
    // Ana's broken commitment is not the team's.
    CommitmentRecords::commitment($this->world->clients['agro'], $this->world->ana, '2026-10-05', '800000.00', ['status' => CommitmentStatus::Broken, 'broken_at' => '2026-10-06 00:15:00']);

    expect(Page::column(($this->team)(), 6))->toBe(['1', '—', '—']);
});

it('sums up the team', function () {
    $response = ($this->team)();

    $response->assertSeeText('3 asesores · '.Money::format(3_500_000).' vencido en total')
        ->assertSeeText('Total del equipo');
    expect(Page::html($response)->text('//table/tfoot/tr/td[1]'))->toBe('3')
        ->and(Page::html($response)->text('//table/tfoot/tr/td[2]'))->toBe(Money::format(4_100_000));
});

it('links each advisor to their own portfolio', function () {
    $response = ($this->team)();
    $links = array_map(fn ($link) => $link->getAttribute('href'), Page::html($response)->all('//table/tbody/tr/td[7]//a'));

    expect($links)->toBe([
        route('asesores.inicio', ['asesor' => 'SDC-50']),
        route('asesores.inicio', ['asesor' => 'SGX-30']),
        route('asesores.inicio', ['asesor' => 'SDC-40']),
    ]);
});

it('takes the manager back to the team from the portfolio of one advisor', function () {
    $response = $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])
        ->get(route('asesores.inicio', ['asesor' => 'SGX-30']))
        ->assertOk();

    $response->assertSeeText('Estás viendo la cartera de Felipe Ospina.');
    expect(Page::html($response)->count('//a[@href="'.route('asesores.equipo.index').'"][contains(.,"Volver a mi equipo")]'))->toBe(1);
});

it('shows only the advisors of the company the manager works in', function () {
    $response = $this->actingAs($this->world->gloria)->get(route('asesores.equipo.index'))->assertOk();

    expect(Page::clientNames($response))->toBe(['Felipe Ospina']);
});

it('never lists advisors outside the team', function () {
    $response = ($this->team)();

    $response->assertDontSeeText('Carlos Pérez')->assertDontSeeText('Diana Duque')->assertDontSeeText('Hernán Toro');
});

it('says what to do for a manager without a team', function () {
    $lonely = CoreUsers::create(Role::SalesManager, CompanyCode::SGX);

    $this->actingAs($lonely)->get(route('asesores.equipo.index'))
        ->assertOk()
        ->assertSeeText('Aún no tienes asesores a tu cargo');
});

it('is for sales managers only: advisors, analysts and guests are kept out', function () {
    $this->get(route('asesores.equipo.index'))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->ana)->get(route('asesores.equipo.index'))->assertForbidden();
    $this->actingAs($this->world->bruno)->get(route('asesores.equipo.index'))->assertForbidden();
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.equipo.index'))->assertForbidden();
});

it('puts the team on the menu of sales managers only', function () {
    $keys = fn ($user) => array_map(fn ($item) => $item->key, app(NavRegistry::class)->visibleTo($user));

    expect($keys($this->world->gloria))->toContain('asesores.equipo')
        ->and($keys($this->world->ana))->not->toContain('asesores.equipo');
});
