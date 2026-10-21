<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterVersions;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Comisiones\Feature\Concerns\ClosedPeriods;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| «Parámetros» (§7.2): the rates, minimums, tranches, teams, derived
| commissions and options in force on a date, one tab each, and the history
| of every key (its bitácora). Only admins and portfolio managers see it.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->manager = ComisionesUsers::analyst();

    // The stored data of code 14 with another sale rate.
    $this->code14 = fn (string $saleRate = '1') => [
        'nombre' => 'Mónica Rivera',
        'persona' => ['id' => 'monica-rivera', 'nombre' => 'Mónica Rivera'],
        'tasa_venta' => $saleRate,
        'tasa_recaudo' => '0.75',
        'cumplimiento_minimo' => '90',
        'alcance' => 'regular',
        'usa_tramos' => true,
    ];
});

/**
 * The text of a cell of a row: //tr[@data-key=$key]/td[@data-col=$column].
 */
function parameterCell(string $html, string $key, string $column): string
{
    return Html::parse($html)->text("//tr[@data-key='{$key}']/td[@data-col='{$column}']");
}

it('serves the parameters under /comisiones/parametros, one URL per tab and one per history', function () {
    expect(route('comisiones.parametros.index', absolute: false))->toBe('/comisiones/parametros')
        ->and(route('comisiones.parametros.index', ['pestana' => 'tramos', 'fecha' => '2026-11-01'], absolute: false))->toBe('/comisiones/parametros/tramos?fecha=2026-11-01')
        ->and(route('comisiones.parametros.historial', ['tipo' => 'codigo', 'clave' => '14'], absolute: false))->toBe('/comisiones/parametros/codigo/14')
        ->and(route('comisiones.parametros.historial', ['tipo' => 'derivada', 'clave' => '81.1'], absolute: false))->toBe('/comisiones/parametros/derivada/81.1');

    $this->actingAs($this->manager)->get('/comisiones/parametros/otra')->assertNotFound();
    $this->actingAs($this->manager)->get('/comisiones/parametros/codigo/999')->assertNotFound();
    $this->actingAs($this->manager)->get('/comisiones/parametros/inventado/14')->assertNotFound();
});

it('sends guests to the login', function () {
    $this->get('/comisiones/parametros')->assertRedirect('/login');
    $this->get('/comisiones/parametros/codigo/14')->assertRedirect('/login');
});

it('forbids the parameters to roles without comisiones.parameters', function (array $roles) {
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get('/comisiones/parametros')->assertForbidden();
    $this->actingAs($user)->get('/comisiones/parametros/equipos')->assertForbidden();
    $this->actingAs($user)->get('/comisiones/parametros/codigo/14')->assertForbidden();
})->with([
    'aprobador' => [[ModuleRoles::APPROVER]],
    'consulta' => [[ModuleRoles::VIEWER]],
    'asesor' => [[Role::SalesAdvisor->value]],
    'sin rol' => [[]],
]);

it('opens the parameters to the admin and the portfolio manager, with the menu entry', function () {
    foreach ([$this->manager, ComisionesUsers::create([Role::Admin->value], 'admin@segurex.com', 'Alicia Admin')] as $user) {
        $html = Html::parse($this->actingAs($user)->get('/comisiones/parametros')->assertOk()->getContent());

        expect($html->text('//h1'))->toBe('Parámetros de comisiones')
            ->and($html->count("//a[@aria-current='page' and contains(@href, '/comisiones/parametros')]"))->toBeGreaterThan(0);
    }
});

it('shows the five tabs, the codes first, in force today', function () {
    $response = $this->actingAs($this->manager)->get('/comisiones/parametros')->assertOk()
        ->assertSeeText('Códigos')
        ->assertSeeText('Tramos de recaudo')
        ->assertSeeText('Equipos de directores')
        ->assertSeeText('Comisiones derivadas')
        ->assertSeeText('Opciones');

    $html = $response->getContent();

    expect(Html::parse($html)->one("//input[@name='fecha']")->getAttribute('value'))->toBe('2026-10-20');

    // Annex 1: code 16 has its own rates and an 80 % minimum; 85 collects without tranches.
    expect(parameterCell($html, '16', 'tasa_venta'))->toBe('1,25 %')
        ->and(parameterCell($html, '16', 'tasa_recaudo'))->toBe('1,25 %')
        ->and(parameterCell($html, '16', 'cumplimiento_minimo'))->toBe('80 %')
        ->and(parameterCell($html, '16', 'alcance'))->toBe('Regular')
        ->and(parameterCell($html, '16', 'persona'))->toBe('Carolina Ramírez')
        ->and(parameterCell($html, '85', 'tasa_recaudo'))->toBe('1,5 %')
        ->and(parameterCell($html, '85', 'tramos'))->toBe('Sin tramos')
        ->and(parameterCell($html, '14', 'tramos'))->toBe('Con tramos')
        ->and(parameterCell($html, '81', 'alcance'))->toBe('Aparte')
        ->and(parameterCell($html, '80', 'alcance'))->toBe('Control')
        ->and(parameterCell($html, '16', 'vigencia'))->toBe('Desde 01/01/2026');

    // Sorted by code number, 100 last.
    $keys = array_map(fn (DOMElement $row) => $row->getAttribute('data-key'), Html::parse($html)->all('//tr[@data-key]'));

    expect($keys[0])->toBe('14')
        ->and(end($keys))->toBe('100')
        ->and($keys)->toHaveCount(31);
});

it('shows the tranches, the teams, the derived commissions and the options', function () {
    $tranches = $this->actingAs($this->manager)->get('/comisiones/parametros/tramos')->assertOk()->getContent();

    expect(parameterCell($tranches, '0', 'hasta'))->toBe('65')
        ->and(parameterCell($tranches, '0', 'participacion'))->toBe('100 %')
        ->and(parameterCell($tranches, '96', 'participacion'))->toBe('50 %')
        ->and(parameterCell($tranches, '126', 'hasta'))->toBe('Sin límite')
        ->and(parameterCell($tranches, '126', 'participacion'))->toBe('0 %');

    $teams = $this->actingAs($this->manager)->get('/comisiones/parametros/equipos')->assertOk()->getContent();

    expect(parameterCell($teams, 'distribucion', 'canal'))->toBe('Distribución')
        ->and(parameterCell($teams, 'distribucion', 'director'))->toBe('Jessika Quintero')
        ->and(parameterCell($teams, 'distribucion', 'tasa'))->toBe('0,2 %')
        ->and(parameterCell($teams, 'distribucion', 'codigo_asociado'))->toBe('53')
        ->and(parameterCell($teams, 'construccion-institucional', 'miembros'))->toBe('85');

    $derived = $this->actingAs($this->manager)->get('/comisiones/parametros/derivadas')->assertOk()->getContent();

    expect(parameterCell($derived, '81.1', 'base'))->toBe('Venta')
        ->and(parameterCell($derived, '81.1', 'tasa'))->toBe('0,1 %')
        ->and(parameterCell($derived, '85.1', 'codigo_origen'))->toBe('85')
        ->and(parameterCell($derived, '85.1', 'base'))->toBe('Recaudo')
        ->and(parameterCell($derived, '85.1', 'persona'))->toBe('Sergio Caballero');

    // No options stored: the defaults (the Excel behaviour) apply.
    $options = $this->actingAs($this->manager)->get('/comisiones/parametros/opciones')->assertOk()
        ->assertSeeText('Aún no hay una versión guardada de las opciones: se usan los valores por defecto, que reproducen el Excel.')
        ->getContent();

    expect(parameterCell($options, 'base_venta', 'valor'))->toBe('Valor reportado por SAP')
        ->and(parameterCell($options, 'cruces_en_derivadas', 'valor'))->toBe('No')
        ->and(parameterCell($options, 'alcance_cumplimiento', 'valor'))->toBe('Por código')
        ->and(parameterCell($options, 'alcance_cumplimiento', 'punto'))->toBe('14.6');
});

it('shows the versions in force on another date and marks the changes to come', function () {
    $versions = app(ParameterVersions::class);
    $versions->add('codigo', '14', '2026-11-01', ($this->code14)('1'), 'Nueva tasa de venta acordada', $this->manager);
    $versions->add('codigo', '99', '2026-11-01', [...($this->code14)('0.5'), 'nombre' => 'Código 99', 'persona' => null], 'Código nuevo de SAP', $this->manager);

    $today = $this->actingAs($this->manager)->get('/comisiones/parametros')->assertOk()->getContent();

    expect(parameterCell($today, '14', 'tasa_venta'))->toBe('0,75 %')
        ->and(parameterCell($today, '14', 'vigencia'))->toBe('Desde 01/01/2026 Cambia el 01/11/2026')
        ->and(parameterCell($today, '99', 'vigencia'))->toBe('Empieza el 01/11/2026')
        ->and(parameterCell($today, '99', 'persona'))->toBe('Código 99');

    $november = $this->actingAs($this->manager)->get('/comisiones/parametros?fecha=2026-11-15')->assertOk()->getContent();

    expect(Html::parse($november)->one("//input[@name='fecha']")->getAttribute('value'))->toBe('2026-11-15')
        ->and(parameterCell($november, '14', 'tasa_venta'))->toBe('1 %')
        ->and(parameterCell($november, '14', 'vigencia'))->toBe('Desde 01/11/2026')
        ->and(parameterCell($november, '99', 'tasa_venta'))->toBe('0,5 %');

    // A date that is not a day falls back to today.
    expect(parameterCell($this->actingAs($this->manager)->get('/comisiones/parametros?fecha=2026-13-45')->assertOk()->getContent(), '14', 'tasa_venta'))->toBe('0,75 %');
});

it('shows the history of a key: validity, values, motive, author and date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-21 15:30:00', 'America/Bogota'));
    app(ParameterVersions::class)->add('codigo', '14', '2026-11-01', ($this->code14)('1'), 'Nueva tasa de venta acordada', $this->manager);

    $response = $this->actingAs($this->manager)->get('/comisiones/parametros/codigo/14')->assertOk()
        ->assertSeeText('Historial del código 14')
        ->assertSeeText('Mónica Rivera');

    $html = Html::parse($response->getContent());
    $rows = $html->all('//tr[@data-version]');

    expect($rows)->toHaveCount(2);

    // Newest first.
    $new = '//tr[@data-version][1]';
    $seed = '//tr[@data-version][2]';

    expect($html->text("{$new}/td[@data-col='desde']"))->toBe('01/11/2026')
        ->and($html->text("{$new}/td[@data-col='hasta']"))->toBe('Abierta')
        ->and($html->text("{$new}/td[@data-col='valores']"))->toContain('Tasa de venta: 1 %')
        ->and($html->text("{$new}/td[@data-col='motivo']"))->toBe('Nueva tasa de venta acordada')
        ->and($html->text("{$new}/td[@data-col='autor']"))->toBe('Ana Gómez')
        ->and($html->text("{$new}/td[@data-col='registro']"))->toBe('21/10/2026 15:30')
        ->and($html->text("{$seed}/td[@data-col='desde']"))->toBe('01/01/2026')
        ->and($html->text("{$seed}/td[@data-col='hasta']"))->toBe('31/10/2026')
        ->and($html->text("{$seed}/td[@data-col='valores']"))->toContain('Tasa de venta: 0,75 %')
        ->and($html->text("{$seed}/td[@data-col='valores']"))->toContain('Mínimo de cumplimiento: 90 %')
        ->and($html->text("{$seed}/td[@data-col='autor']"))->toBe('Sistema')
        ->and($html->text("{$seed}/td[@data-col='estado']"))->toBe('Vigente');
});

it('shows the history of the tranches, a team, a derived commission and the options', function () {
    $this->actingAs($this->manager)->get('/comisiones/parametros/tramos/tramos')->assertOk()
        ->assertSeeText('Historial de la tabla de tramos')
        ->assertSeeText('0 a 65 días: 100 %')
        ->assertSeeText('126 días o más: 0 %');

    $this->actingAs($this->manager)->get('/comisiones/parametros/equipo/distribucion')->assertOk()
        ->assertSeeText('Historial del equipo Distribución')
        ->assertSeeText('14, 15, 16, 17, 18, 23, 32, 34, 41, 43, 51, 53, 67, 69');

    $this->actingAs($this->manager)->get('/comisiones/parametros/derivada/85.1')->assertOk()
        ->assertSeeText('Historial de la comisión derivada 85.1')
        ->assertSeeText('Base: Recaudo');

    $this->actingAs($this->manager)->get('/comisiones/parametros/opciones/opciones')->assertOk()
        ->assertSeeText('Historial de las opciones de liquidación')
        ->assertSeeText('Aún no hay una versión guardada de las opciones: se usan los valores por defecto, que reproducen el Excel.');
});

it('tells from which day new versions may start when a period is closed', function () {
    $this->actingAs($this->manager)->get('/comisiones/parametros')->assertOk()
        ->assertDontSeeText('Las versiones nuevas deben empezar después del');

    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    ClosedPeriods::close($this, new SapBatches($this->fakes->ingesta), $this->manager, ComisionesUsers::approver());

    $this->actingAs($this->manager)->get('/comisiones/parametros')->assertOk()
        ->assertSeeText('Las versiones nuevas deben empezar después del 31/07/2026, el fin del último período cerrado (julio de 2026).');
});
