<?php

use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Asesores\Support\Spreadsheet;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
});

it('downloads the portfolio of the advisor as an Excel file', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar'))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($response->headers->get('Content-Disposition'))->toContain('cartera-por-cliente-2026-10-16.xlsx');
});

it('has a sheet of clients and a sheet of their open documents', function () {
    $sheets = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar')));

    expect(array_keys($sheets))->toBe(['Clientes', 'Documentos'])
        ->and($sheets['Clientes'][0])->toBe(['Código', 'Cliente', 'NIT', 'Empresa', 'Asesor', 'Estado', 'Saldo (COP)', 'Vencido (COP)', 'Días de mora', 'Última gestión'])
        ->and(Spreadsheet::column($sheets['Clientes'], 1))->toBe(['Agro Arango', 'Ferretería Franco'])
        ->and(Spreadsheet::column($sheets['Documentos'], 1))->toBe(['Agro Arango', 'Agro Arango', 'Ferretería Franco']);
});

it('writes amounts as numbers and dates as dates, so Excel can add and sort them', function () {
    $sheets = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar')));
    $agro = $sheets['Clientes'][1];
    $document = $sheets['Documentos'][1];

    expect($agro[6])->toBeNumeric()->and((float) $agro[6])->toBe(7_000_000.0)
        ->and((float) $agro[7])->toBe(5_000_000.0)
        ->and((int) $agro[8])->toBe(100)
        ->and($agro[9])->toBeInstanceOf(DateTimeInterface::class)
        ->and($document[6])->toBeInstanceOf(DateTimeInterface::class)
        ->and($document[7])->toBeInstanceOf(DateTimeInterface::class)
        ->and((float) $document[10])->toBe(5_000_000.0);
});

it('describes each document: type, due date, days and age', function () {
    $sheets = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar')));
    $late = $sheets['Documentos'][1];
    $notDue = $sheets['Documentos'][2];

    expect($late[5])->toBe('Factura')
        ->and((int) $late[8])->toBe(100)
        ->and($late[11])->toBe('Más de 90 días')
        ->and((int) $notDue[8])->toBe(-10)
        ->and($notDue[11])->toBe('Sin vencer');
});

it('writes text that looks like a formula as text, never as a formula', function () {
    $this->agro->update(['name' => '=HYPERLINK("http://evil.example","Agro")', 'tax_id' => '+1-2']);

    $sheets = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar')));
    $cell = $sheets['Clientes'][1][1] ?? $sheets['Clientes'][2][1];
    $names = Spreadsheet::column($sheets['Clientes'], 1);

    expect($names)->toContain('=HYPERLINK("http://evil.example","Agro")');

    // A formula cell reads back as its result or empty; a text cell keeps the text.
    $xml = file_get_contents('zip://'.($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar'))->baseResponse->getFile()->getPathname()).'#xl/worksheets/sheet1.xml');

    expect($xml)->not->toContain('<f>')->and($cell)->not->toBeNull();
});

it('follows the filters and the order of the list', function () {
    $overdue = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar', ['vencidos' => '1'])));
    $reversed = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar', ['sort' => 'name', 'direction' => 'desc'])));

    expect(Spreadsheet::column($overdue['Clientes'], 1))->toBe(['Agro Arango'])
        ->and(Spreadsheet::column($overdue['Documentos'], 1))->toBe(['Agro Arango', 'Agro Arango'])
        ->and(Spreadsheet::column($reversed['Clientes'], 1))->toBe(['Ferretería Franco', 'Agro Arango']);
});

it('never holds the clients of another advisor, however the URL asks for them', function (array $query) {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar', $query))->assertOk();
    $sheets = Spreadsheet::of($response);
    $everything = json_encode($sheets, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

    expect($everything)->not->toContain('Bodega Bedoya')
        ->not->toContain('Diana Duque')
        ->not->toContain('Comercial Castaño')
        ->not->toContain('Granos Giraldo')
        ->not->toContain('Equipos Echeverri')
        ->not->toContain('Distribuciones Duque')
        ->and(Spreadsheet::column($sheets['Clientes'], 1))->each->toBeIn(['Agro Arango', 'Ferretería Franco']);
})->with([
    'nothing' => [[]],
    'another advisor' => [['asesor' => 'SGX-20']],
    'the same code in the other company' => [['asesor' => 'SDC-12']],
    'the team of a manager' => [['asesor' => 'SGX-30']],
    'a search for their client' => [['buscar' => 'Bodega']],
    'a search for their NIT' => [['buscar' => '900']],
]);

it('is empty, not somebody else’s, when the URL names another advisor', function () {
    $sheets = Spreadsheet::of($this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar', ['asesor' => 'SGX-20'])));

    expect(Spreadsheet::column($sheets['Clientes'], 1))->toBe([])
        ->and(Spreadsheet::column($sheets['Documentos'], 1))->toBe([]);
});

it('gives each advisor their own file', function () {
    $bruno = Spreadsheet::of($this->actingAs($this->world->bruno)->get(route('asesores.clientes.exportar')));

    expect(Spreadsheet::column($bruno['Clientes'], 1))->toBe(['Bodega Bedoya']);
});

it('gives a sales manager the clients of the team and no others', function () {
    $sheets = Spreadsheet::of($this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.clientes.exportar')));

    expect(Spreadsheet::column($sheets['Clientes'], 1))->toBe(['Granos Giraldo', 'Comercial Castaño', 'Jardines Jaramillo'])
        ->and(json_encode($sheets, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR))->not->toContain('Agro Arango')->not->toContain('Bodega Bedoya');
});

it('exports one client with their documents', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar_cliente', $this->agro->id))->assertOk();
    $sheets = Spreadsheet::of($response);

    expect($response->headers->get('Content-Disposition'))->toContain('cartera-'.$this->agro->code.'-2026-10-16.xlsx')
        ->and(Spreadsheet::column($sheets['Clientes'], 1))->toBe(['Agro Arango'])
        ->and(Spreadsheet::column($sheets['Documentos'], 1))->toBe(['Agro Arango', 'Agro Arango']);
});

it('does not export a client of another advisor, as if they did not exist', function (string $client) {
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar_cliente', $this->world->clients[$client]->id))->assertNotFound();
})->with(['bodega', 'distribuciones', 'equipos', 'comercial']);

it('does not export an unknown client either', function () {
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar_cliente', 987654))->assertNotFound();
});

it('keeps a sales manager to the clients of the team in the one-client export too', function () {
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.exportar_cliente', $this->agro->id))->assertNotFound();
    $this->actingAs($this->world->gloria)->get(route('asesores.clientes.exportar_cliente', $this->world->clients['comercial']->id))->assertOk();
});

it('leaves a trace of every download', function () {
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar', ['vencidos' => '1']));
    $this->actingAs($this->world->ana)->get(route('asesores.clientes.exportar_cliente', $this->agro->id));

    $portfolio = AuditLogRecord::query()->where('action', 'asesores.portfolio.exported')->sole();
    $client = AuditLogRecord::query()->where('action', 'asesores.client.exported')->sole();

    expect($portfolio->user_id)->toBe($this->world->ana->id)
        ->and($portfolio->context)->toMatchArray(['clients' => 1, 'invoices' => 2, 'filters' => ['vencidos' => '1']])
        ->and($client->subject_id)->toBe((string) $this->agro->id)
        ->and($client->context)->toMatchArray(['invoices' => 2]);
});

it('offers the download on the list, with the filters, and on the client file', function () {
    $list = $this->actingAs($this->world->ana)->get(route('asesores.clientes.index', ['vencidos' => '1']));
    $file = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id));

    expect(Page::html($list)->count('//a[@href="'.route('asesores.clientes.exportar', ['vencidos' => '1']).'"]'))->toBe(1)
        ->and(Page::html($file)->count('//a[@href="'.route('asesores.clientes.exportar_cliente', $this->agro->id).'"]'))->toBe(1);
});

it('is closed to guests and to the roles that have their own screens', function () {
    $this->get(route('asesores.clientes.exportar'))->assertRedirect(route('core.login'));
    $this->get(route('asesores.clientes.exportar_cliente', $this->agro->id))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.clientes.exportar'))->assertForbidden();
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.clientes.exportar_cliente', $this->agro->id))->assertForbidden();
});
