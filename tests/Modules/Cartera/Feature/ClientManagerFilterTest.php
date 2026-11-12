<?php

use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CarteraUsers::analyst();
    $this->manager = CarteraUsers::manager();

    CarteraWorld::client($this->fakes, name: 'Cliente de la analista', overdue: 3_000_000, manager: Make::userRef(id: $this->analyst->id, name: 'Analista de prueba'));
    CarteraWorld::client($this->fakes, name: 'Cliente del gerente', overdue: 2_000_000, manager: Make::userRef(id: $this->manager->id, name: 'Gerente de prueba'));
    CarteraWorld::client($this->fakes, name: 'Cliente sin gestor', overdue: 1_000_000);
});

/**
 * The names in the "Cliente" column of the Excel export of the list.
 *
 * @return list<string>
 */
function exportedClientNames(TestResponse $response): array
{
    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $names = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $index => $row) {
            if ($index > 1) {
                $names[] = $row->toArray()[2];
            }
        }
    }

    $reader->close();

    return $names;
}

it('opens on "Mi cartera" for the analyst', function () {
    $this->actingAs($this->analyst)
        ->get('/cartera/clientes')
        ->assertOk()
        ->assertSeeText('Cliente de la analista')
        ->assertDontSeeText('Cliente del gerente')
        ->assertDontSeeText('Cliente sin gestor')
        ->assertSee('<option value="mios" selected>', false);
});

it('opens on the whole portfolio for the portfolio manager', function () {
    $this->actingAs($this->manager)
        ->get('/cartera/clientes')
        ->assertOk()
        ->assertSeeText('Cliente de la analista')
        ->assertSeeText('Cliente del gerente')
        ->assertSeeText('Cliente sin gestor')
        ->assertSee('<option value="todos" selected>', false);
});

it('lets the analyst switch to the whole portfolio', function () {
    $this->actingAs($this->analyst)
        ->get('/cartera/clientes?gestor=todos')
        ->assertSeeText('Cliente de la analista')
        ->assertSeeText('Cliente del gerente')
        ->assertSeeText('Cliente sin gestor');
});

it('shows only the clients without a manager with "Sin asignar"', function () {
    $this->actingAs($this->manager)
        ->get('/cartera/clientes?gestor=sin_asignar')
        ->assertSeeText('Cliente sin gestor')
        ->assertDontSeeText('Cliente de la analista')
        ->assertDontSeeText('Cliente del gerente');
});

it('lets the portfolio manager see their own clients with "Mi cartera"', function () {
    $this->actingAs($this->manager)
        ->get('/cartera/clientes?gestor=mios')
        ->assertSeeText('Cliente del gerente')
        ->assertDontSeeText('Cliente de la analista')
        ->assertDontSeeText('Cliente sin gestor');
});

it('clears the filters to the whole portfolio, also for the analyst', function () {
    $this->actingAs($this->analyst)
        ->get('/cartera/clientes')
        ->assertSee('href="'.e(route('cartera.clientes.index', ['gestor' => 'todos'])).'"', false);
});

it('keeps the manager filter in the export button', function () {
    $this->actingAs($this->analyst)
        ->get('/cartera/clientes')
        ->assertSee('href="'.e(route('cartera.clientes.exportar', ['gestor' => 'mios'])).'"', false);

    $this->actingAs($this->manager)
        ->get('/cartera/clientes?gestor=sin_asignar')
        ->assertSee('href="'.e(route('cartera.clientes.exportar', ['gestor' => 'sin_asignar'])).'"', false);
});

describe('the Excel export', function () {
    it('holds the clients of the analyst by default, as the list does', function () {
        expect(exportedClientNames($this->actingAs($this->analyst)->get('/cartera/clientes/exportar')))->toBe(['Cliente de la analista']);
    });

    it('holds the whole portfolio when asked, or for the portfolio manager', function () {
        expect(exportedClientNames($this->actingAs($this->analyst)->get('/cartera/clientes/exportar?gestor=todos')))->toHaveCount(3)
            ->and(exportedClientNames($this->actingAs($this->manager)->get('/cartera/clientes/exportar')))->toHaveCount(3);
    });

    it('holds only the clients without a manager with "Sin asignar"', function () {
        expect(exportedClientNames($this->actingAs($this->manager)->get('/cartera/clientes/exportar?gestor=sin_asignar')))->toBe(['Cliente sin gestor']);
    });

    it('audits the manager filter it used', function () {
        $this->actingAs($this->analyst)->get('/cartera/clientes/exportar')->assertOk();

        $this->fakes->audit->assertRecorded('cartera.client_list.exported', fn ($entry): bool => $entry->context === ['rows' => 1, 'filters' => ['gestor' => 'mios']]);
    });
});
