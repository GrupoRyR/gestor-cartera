<?php

use App\Modules\Cartera\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->today = CarbonImmutable::today()->toDateString();
});

/**
 * The sheets of the workbook a response carries, as sheet name => rows of
 * plain values (dates as Y-m-d or Y-m-d H:i), read back with OpenSpout.
 *
 * @return array<string, list<list<mixed>>>
 */
function workbookOf(TestResponse $response): array
{
    $path = $response->baseResponse->getFile()->getPathname();
    $reader = new Reader;
    $reader->open($path);
    $sheets = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];

        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(function (mixed $value): mixed {
                if ($value instanceof DateTimeInterface) {
                    return $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i');
                }

                return $value;
            }, $row->toArray());
        }

        $sheets[$sheet->getName()] = $rows;
    }

    $reader->close();

    return $sheets;
}

it('serves the exports under /cartera/clientes', function () {
    expect(route('cartera.clientes.exportar', absolute: false))->toBe('/cartera/clientes/exportar')
        ->and(route('cartera.clientes.exportar-ficha', 12, absolute: false))->toBe('/cartera/clientes/12/exportar');
});

it('sends guests to the sign-in page', function () {
    $this->get('/cartera/clientes/exportar')->assertRedirect('/login');
    $this->get('/cartera/clientes/1/exportar')->assertRedirect('/login');
});

it('is for those who may export, not for those who may only look or work elsewhere', function (?Role $role) {
    $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
    $user = $role === null ? CarteraUsers::viewer() : CarteraUsers::create($role);

    $this->actingAs($user)->get('/cartera/clientes/exportar')->assertForbidden();
    $this->actingAs($user)->get("/cartera/clientes/{$client->id}/exportar")->assertForbidden();

    $this->fakes->audit->assertNothingRecorded();
})->with([
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'only cartera.view' => [null],
]);

describe('the client list', function () {
    it('downloads as an Excel file named with the day', function (Role $role) {
        CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::create($role))
            ->get('/cartera/clientes/exportar')
            ->assertOk()
            ->assertHeader('Content-Type', SpreadsheetDownload::MIME_TYPE)
            ->assertDownload("clientes-cartera-{$this->today}.xlsx");
    })->with([Role::PortfolioManager, Role::PortfolioAnalyst]);

    it('holds a row per client with the figures as numbers', function () {
        $client = CarteraWorld::client(
            $this->fakes,
            name: 'Ferretería El Tornillo SAS',
            overdue: 2_000_000,
            notDue: 500_000,
            daysOverdue: 38,
            status: ClientStatus::Overdue,
            advisor: Make::advisor(name: 'Carlos Pérez'),
            manager: Make::userRef(name: 'María Paula Rojas'),
            lastActivityAt: CarbonImmutable::parse('2026-10-10 15:30'),
            taxId: '0900123456',
        );

        $sheets = workbookOf($this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes/exportar'));

        expect(array_keys($sheets))->toBe(['Clientes'])
            ->and($sheets['Clientes'][0])->toBe(['Empresa', 'Código SAP', 'Cliente', 'NIT', 'Estado', 'Total', 'Vencido', 'Días de mora', 'Asesor', 'Gestor responsable', 'Última gestión'])
            ->and($sheets['Clientes'])->toHaveCount(2)
            ->and($sheets['Clientes'][1])->toBe([
                'SGX', $client->code, 'Ferretería El Tornillo SAS', '0900123456', 'Vencida',
                2_500_000, 2_000_000, 38, 'Carlos Pérez', 'María Paula Rojas', '2026-10-10',
            ]);
    });

    it('leaves empty what a client does not have', function () {
        CarteraWorld::client($this->fakes, name: 'Cliente solo', notDue: 100_000);

        $row = workbookOf($this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes/exportar'))['Clientes'][1];

        expect($row[8])->toBe('')->and($row[9])->toBe('')->and($row[10])->toBe('');
    });

    it('follows the filters and the order of the list, without its paging', function () {
        config(['cartera.por_pagina' => 1]);
        CarteraWorld::client($this->fakes, name: 'Moroso chico', overdue: 1_000_000, status: ClientStatus::Overdue);
        CarteraWorld::client($this->fakes, name: 'Moroso grande', overdue: 5_000_000, status: ClientStatus::Overdue);
        CarteraWorld::client($this->fakes, name: 'Cliente en gestión', overdue: 9_000_000, status: ClientStatus::InProgress);

        $names = fn (string $query) => array_column(
            array_slice(workbookOf($this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/exportar{$query}"))['Clientes'], 1),
            2,
        );

        expect($names(''))->toBe(['Cliente en gestión', 'Moroso grande', 'Moroso chico'])
            ->and($names('?estado=vencida'))->toBe(['Moroso grande', 'Moroso chico'])
            ->and($names('?estado=vencida&sort=name&direction=asc'))->toBe(['Moroso chico', 'Moroso grande'])
            ->and($names('?buscar=chico'))->toBe(['Moroso chico']);
    });

    it('holds only the clients the user may reach', function () {
        CarteraWorld::client($this->fakes, name: 'Cliente de SGX', company: CompanyCode::SGX, overdue: 1_000_000);
        CarteraWorld::client($this->fakes, name: 'Cliente de SDC', company: CompanyCode::SDC, overdue: 1_000_000);
        $analyst = CarteraUsers::analyst();

        $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
        $names = array_column(array_slice(workbookOf($this->actingAs($analyst)->get('/cartera/clientes/exportar'))['Clientes'], 1), 2);

        expect($names)->toBe(['Cliente de SDC']);
    });

    it('writes text as text, so a name that looks like a formula never runs', function () {
        CarteraWorld::client($this->fakes, name: '=HYPERLINK("http://ejemplo.invalid","Pague aquí")', overdue: 1_000_000);

        $response = $this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes/exportar');
        $path = $response->baseResponse->getFile()->getPathname();

        $zip = new ZipArchive;
        $zip->open($path);
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        expect($sheetXml)->not->toContain('<f>')->and($sheetXml)->not->toContain('<f ')
            ->and(workbookOf($response)['Clientes'][1][2])->toBe('=HYPERLINK("http://ejemplo.invalid","Pague aquí")');
    });

    it('is audited with the filters used and the rows it holds', function () {
        CarteraWorld::client($this->fakes, name: 'Moroso', overdue: 1_000_000, status: ClientStatus::Overdue);

        $this->actingAs(CarteraUsers::analyst())->get('/cartera/clientes/exportar?estado=vencida')->assertOk();

        $this->fakes->audit->assertRecorded('cartera.client_list.exported', fn ($entry): bool => $entry->context === ['rows' => 1, 'filters' => ['estado' => 'vencida']]);
    });
});

describe('the client file', function () {
    beforeEach(function () {
        $this->client = CarteraWorld::client(
            $this->fakes,
            name: 'Ferretería El Tornillo',
            overdue: 2_000_000,
            notDue: 1_000_000,
            daysOverdue: 38,
            status: ClientStatus::Overdue,
            advisor: Make::advisor(name: 'Carlos Pérez'),
            group: 'Distribuidores',
            city: 'Medellín',
            creditLimit: 10_000_000,
            contacts: [Make::contact(name: 'Laura Martínez', email: 'laura@cliente.example', phone: '6015550123', position: 'Tesorera', isPrimary: true)],
        );
        $this->fakes->compromisos->add(Make::commitment(clientId: $this->client->id, amount: 1_200_000, dueDate: CarbonImmutable::parse('2026-11-05'), notes: 'Paga por PSE', createdBy: Make::userRef(name: 'Carlos Pérez')));
        $this->fakes->gestiones->add(
            Make::activity(clientId: $this->client->id, type: ActivityType::Call, summary: 'Llamada: No contesta', result: 'No contesta', details: 'Sin respuesta', user: Make::userRef(name: 'María Paula Rojas'), occurredAt: CarbonImmutable::parse('2026-10-14 09:15')),
            Make::activity(clientId: $this->client->id, type: ActivityType::System, summary: 'Cartera recalculada', result: null, occurredAt: CarbonImmutable::parse('2026-10-15 00:30')),
        );
    });

    it('downloads as an Excel file named after the client and the day', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$this->client->id}/exportar")
            ->assertOk()
            ->assertHeader('Content-Type', SpreadsheetDownload::MIME_TYPE)
            ->assertDownload("cliente-ferreteria-el-tornillo-{$this->today}.xlsx");
    });

    it('holds a sheet for the data, the invoices, the contacts, the commitments and the log', function () {
        $sheets = workbookOf($this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$this->client->id}/exportar"));

        expect(array_keys($sheets))->toBe(['Cliente', 'Facturas abiertas', 'Contactos', 'Compromisos', 'Bitácora']);
    });

    it('writes the data of the client as label and value', function () {
        $data = workbookOf($this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$this->client->id}/exportar"))['Cliente'];
        $byLabel = array_column($data, 1, 0);

        expect($byLabel['Cliente'])->toBe('Ferretería El Tornillo')
            ->and($byLabel['Empresa'])->toBe('Segurex Latam')
            ->and($byLabel['Estado'])->toBe('Vencida')
            ->and($byLabel['Ciudad'])->toBe('Medellín')
            ->and($byLabel['Cupo'])->toBe(10_000_000)
            ->and($byLabel['Total'])->toBe(3_000_000)
            ->and($byLabel['Vencido'])->toBe(2_000_000)
            ->and($byLabel['Días de mora máxima'])->toBe(38)
            ->and($byLabel['Asesor'])->toBe('Carlos Pérez');
    });

    it('lists the open invoices, the contacts, the commitments and the log', function () {
        $sheets = workbookOf($this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$this->client->id}/exportar"));

        expect($sheets['Facturas abiertas'][0])->toBe(['Documento', 'Emisión', 'Vence', 'Días de mora', 'Valor original', 'Saldo'])
            ->and($sheets['Facturas abiertas'])->toHaveCount(3)
            ->and(array_column($sheets['Facturas abiertas'], 3))->toContain(38, -10)
            ->and($sheets['Contactos'][1])->toBe(['Laura Martínez', 'Tesorera', 'laura@cliente.example', '6015550123', 'Sí'])
            ->and($sheets['Compromisos'][1])->toBe(['2026-11-05', 1_200_000, CommitmentStatus::Pending->label(), 'Carlos Pérez', 'Paga por PSE'])
            ->and($sheets['Bitácora'])->toHaveCount(3)
            ->and($sheets['Bitácora'][1][0])->toBe('2026-10-15 00:30')
            ->and($sheets['Bitácora'][1][5])->toBe('Sistema')
            ->and($sheets['Bitácora'][2])->toBe(['2026-10-14 09:15', 'Llamada', 'Llamada: No contesta', 'No contesta', 'Sin respuesta', 'María Paula Rojas']);
    });

    it('answers 404 for a client that does not exist or is out of reach', function () {
        $analyst = CarteraUsers::analyst();

        $this->actingAs($analyst)->get('/cartera/clientes/987654/exportar')->assertNotFound();

        $this->fakes->cartera->restrictToAdvisor(999);

        $this->actingAs($analyst)->get("/cartera/clientes/{$this->client->id}/exportar")->assertNotFound();
        $this->fakes->audit->assertNothingRecorded();
    });

    it('is audited', function () {
        $this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$this->client->id}/exportar")->assertOk();

        $this->fakes->audit->assertRecorded('cartera.client_file.exported', fn ($entry): bool => $entry->subjectType === 'client' && (int) $entry->subjectId === $this->client->id);
    });
});

describe('the buttons', function () {
    it('export the list with the filters on screen', function () {
        CarteraWorld::client($this->fakes, name: 'Cliente A', overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->get('/cartera/clientes?estado=vencida&buscar=tornillo')
            ->assertSee('href="'.e(route('cartera.clientes.exportar', ['buscar' => 'tornillo', 'estado' => 'vencida'])).'"', false)
            ->assertSeeText('Exportar a Excel');
    });

    it('export the client file', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);

        $this->actingAs(CarteraUsers::analyst())
            ->get("/cartera/clientes/{$client->id}")
            ->assertSee('href="'.route('cartera.clientes.exportar-ficha', $client->id).'"', false);
    });

    it('are not there for those who may only look', function () {
        $client = CarteraWorld::client($this->fakes, overdue: 1_000_000);
        $viewer = CarteraUsers::viewer();

        $this->actingAs($viewer)->get('/cartera/clientes')->assertOk()->assertDontSeeText('Exportar a Excel');
        $this->actingAs($viewer)->get("/cartera/clientes/{$client->id}")->assertOk()->assertDontSeeText('Exportar a Excel');
    });
});
