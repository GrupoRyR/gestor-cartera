<?php

use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Core\Support\TeamUsers;

beforeEach(function () {
    $this->seed(CorePermissionSeeder::class);
    $this->admin = TeamUsers::admin();
});

it('lists each company with its grace days and due-soon window', function () {
    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.empresas.index'))->assertOk());

    $headers = array_map(fn (DOMElement $cell): string => Html::clean($cell->textContent), $html->all('//table/thead/tr/th'));
    $rows = array_map(
        fn (DOMElement $row): array => array_map(
            fn (DOMElement $cell): string => Html::clean($cell->textContent),
            array_slice(iterator_to_array($row->getElementsByTagName('td')), 0, 3),
        ),
        $html->all('//table/tbody/tr'),
    );
    $editLinks = array_map(fn (DOMElement $link): string => $link->getAttribute('href'), $html->all('//table/tbody/tr/td[4]//a'));

    expect($headers)->toBe(['Empresa', 'Días de gracia', 'Ventana de «Por vencer»', 'Acciones'])
        ->and($rows)->toBe([
            ['Segurex Latam (SGX)', '15 días', '5 días'],
            ['Soluciones y Derivados (SDC)', '5 días', '5 días'],
        ])
        ->and($editLinks)->toBe([route('core.empresas.edit', 'SGX'), route('core.empresas.edit', 'SDC')])
        ->and(Html::clean($html->one('//table/tbody/tr[1]/td[4]//a')->textContent))->toBe('Editar los parámetros de Segurex Latam');
});

it('lists the values admins saved', function () {
    CompanyRecord::query()->whereKey('SDC')->update(['grace_days' => 1, 'due_soon_days' => 0]);

    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.empresas.index'))->assertOk());

    expect(Html::clean($html->one('//table/tbody/tr[2]/td[2]')->textContent))->toBe('1 día')
        ->and(Html::clean($html->one('//table/tbody/tr[2]/td[3]')->textContent))->toBe('0 días');
});

it('shows the edit form with the current values', function () {
    CompanyRecord::query()->whereKey('SDC')->update(['grace_days' => 7, 'due_soon_days' => 3]);

    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.empresas.edit', 'SDC'))->assertOk());
    $graceDays = $html->labelledControl('Días de gracia');
    $dueSoonDays = $html->labelledControl('Ventana de «Por vencer»');

    expect($html->text('//h1'))->toBe('Soluciones y Derivados')
        ->and($html->text('//title'))->toBe('Soluciones y Derivados · '.config('app.name'))
        ->and($html->one('//main//form[input[@name="_method"][@value="PUT"]]')->getAttribute('action'))->toBe(route('core.empresas.update', 'SDC'))
        ->and($graceDays->getAttribute('name'))->toBe('grace_days')
        ->and($graceDays->getAttribute('value'))->toBe('7')
        ->and($graceDays->getAttribute('min'))->toBe('0')
        ->and($graceDays->getAttribute('max'))->toBe('120')
        ->and($graceDays->hasAttribute('required'))->toBeTrue()
        ->and($html->descriptionsOf($graceDays))->toBe([
            'Días después del vencimiento antes de que una factura cuente como vencida para el estado del cliente.',
        ])
        ->and($dueSoonDays->getAttribute('name'))->toBe('due_soon_days')
        ->and($dueSoonDays->getAttribute('value'))->toBe('3')
        ->and($dueSoonDays->getAttribute('max'))->toBe('60')
        ->and($html->descriptionsOf($dueSoonDays))->toBe([
            'Días antes del vencimiento en los que una factura ya pone al cliente «Por vencer».',
        ]);
});

it('keeps what the admin typed after a failed save', function () {
    $this->actingAs($this->admin)
        ->from(route('core.empresas.edit', 'SGX'))
        ->put(route('core.empresas.update', 'SGX'), ['grace_days' => '200', 'due_soon_days' => '4'])
        ->assertRedirect(route('core.empresas.edit', 'SGX'));

    $html = TeamUsers::html($this->actingAs($this->admin)->get(route('core.empresas.edit', 'SGX'))->assertOk());
    $graceDays = $html->labelledControl('Días de gracia');

    expect($graceDays->getAttribute('value'))->toBe('200')
        ->and($graceDays->getAttribute('aria-invalid'))->toBe('true')
        ->and($html->descriptionsOf($graceDays)[1])->toBe('El campo días de gracia no debe ser mayor que 120.')
        ->and($html->labelledControl('Ventana de «Por vencer»')->getAttribute('value'))->toBe('4');
});
