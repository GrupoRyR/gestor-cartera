<?php

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\Manual\ManualCarteraUpload;
use App\Modules\Ingesta\Application\Manual\ManualPreview;
use App\Modules\Ingesta\Application\Manual\UnreadableWorkbook;
use App\Modules\Ingesta\Database\Seeders\IngestaPermissionSeeder;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\FakeAuditLogger;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Modules\Ingesta\Support\IngestaRows;
use Tests\Modules\Ingesta\Support\IngestaUsers;
use Tests\Modules\Ingesta\Support\IngestaWorkbooks;

beforeEach(function () {
    $this->seed(IngestaPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'America/Bogota'));
    Storage::fake('local');
    Event::fake([BatchAccepted::class]);
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany);
    $this->app->instance(AuditLogger::class, $this->audit = new FakeAuditLogger);
});

afterEach(function () {
    IngestaWorkbooks::cleanup();
});

/**
 * Posts the upload form with a workbook; the fields can be overridden.
 *
 * @param  array<string, mixed>  $fields
 */
function ingestaUpload(User $user, string|UploadedFile $workbook, array $fields = []): TestResponse
{
    $file = $workbook instanceof UploadedFile ? $workbook : IngestaWorkbooks::upload($workbook);

    return test()->actingAs($user)->post('/ingesta/carga-manual', array_merge([
        'empresa' => 'SGX',
        'fecha_corte' => '2026-10-15',
        'archivo' => $file,
    ], $fields));
}

/**
 * The tokens of the uploads waiting in the session.
 *
 * @return list<string>
 */
function ingestaPendingTokens(): array
{
    return array_keys((array) session('ingesta_manual', []));
}

/**
 * The preview of a workbook for SGX with the 2026-10-15 cutoff.
 */
function ingestaPrepare(string $path): ManualPreview
{
    return app(ManualCarteraUpload::class)->prepare($path, CompanyCode::SGX, CarbonImmutable::parse('2026-10-15', 'America/Bogota'), null);
}

it('serves the manual upload under /ingesta/carga-manual', function () {
    $token = '0193a8c4-5d1e-7c3a-9b2f-1a2b3c4d5e6f';

    expect(route('ingesta.manual.create', absolute: false))->toBe('/ingesta/carga-manual')
        ->and(route('ingesta.manual.preview', absolute: false))->toBe('/ingesta/carga-manual')
        ->and(route('ingesta.manual.confirm', $token, absolute: false))->toBe("/ingesta/carga-manual/{$token}/confirmar")
        ->and(route('ingesta.manual.discard', $token, absolute: false))->toBe("/ingesta/carga-manual/{$token}/descartar");
});

it('opens the form for the cartera roles and links it from the history', function (Role $role) {
    $user = IngestaUsers::create($role);

    $this->actingAs($user)->get('/ingesta/carga-manual')
        ->assertOk()
        ->assertSeeText('Carga manual de cartera')
        ->assertSee('enctype="multipart/form-data"', false)
        ->assertSee('value="2026-10-15"', false);

    $this->actingAs($user)->get('/ingesta')->assertOk()->assertSee(route('ingesta.manual.create'), false);
})->with(IngestaUsers::allowed());

it('forbids the manual upload to every other role', function (?Role $role) {
    $user = IngestaUsers::create($role);

    $this->actingAs($user)->get('/ingesta/carga-manual')->assertForbidden();
    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]))->assertForbidden();

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with(IngestaUsers::forbidden());

it('sends guests to the sign-in page', function () {
    $this->get('/ingesta/carga-manual')->assertRedirect('/login');
    $this->post('/ingesta/carga-manual', [])->assertRedirect('/login');
});

it('offers only the companies the user may see', function () {
    $this->app->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SGX, [CompanyCode::SGX]));
    $user = IngestaUsers::create(Role::PortfolioAnalyst);

    $this->actingAs($user)->get('/ingesta/carga-manual')
        ->assertOk()
        ->assertSee('<option value="SGX" selected>', false)
        ->assertDontSee('value="SDC"', false);

    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]), ['empresa' => 'SDC'])->assertSessionHasErrors('empresa');
});

it('validates the form', function (array $fields, string $field, string $message) {
    $user = IngestaUsers::create(Role::PortfolioAnalyst);

    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]), $fields)
        ->assertSessionHasErrors([$field => $message]);

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'future cutoff' => [['fecha_corte' => '2026-10-16'], 'fecha_corte', 'La fecha de corte no puede ser posterior a hoy.'],
    'bad cutoff' => [['fecha_corte' => '15/10/2026'], 'fecha_corte', 'El campo fecha de corte debe tener el formato Y-m-d.'],
    'no company' => [['empresa' => ''], 'empresa', 'El campo empresa es obligatorio.'],
    'unknown company' => [['empresa' => 'XYZ'], 'empresa', 'El valor elegido en empresa no es válido.'],
    'not xlsx' => [['archivo' => UploadedFile::fake()->create('cartera.csv', 10, 'text/csv')], 'archivo', 'Sube el Excel de cartera en formato .xlsx.'],
    'too big' => [['archivo' => UploadedFile::fake()->create('cartera.xlsx', 20 * 1024 + 1)], 'archivo', 'El archivo pesa más de 20 MB.'],
]);

it('finds the header row below the title rows and builds the 38 cartera columns', function () {
    $path = IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()], before: [['Segurex Latam'], ['Cartera del día'], [], ['Generado: 15/10/2026']]);

    $preview = ingestaPrepare($path);

    expect($preview->errors)->toBe([])
        ->and($preview->lines)->toBe([6])
        ->and($preview->ignoredHeaders)->toBe([])
        ->and($preview->batch->dataset)->toBe(Dataset::Receivables)
        ->and($preview->batch->source)->toBe(LoadSource::Manual)
        ->and($preview->batch->rows)->toBe([IngestaRows::cartera([
            'slp_code' => null,
            'email_asesor' => null,
            'codigo_sn' => 'C001',
            'nombre_sn' => 'Ferretería Ñandú SAS',
            'nit' => '900123456',
            'grupo' => 'Distribuidores',
            'municipio' => 'Bogotá',
            'cuenta_visible' => '13050505',
            'tipo_doc' => '13',
            'documento' => '9280',
            'documento_base' => '9280',
            'referencia_1' => '9280',
            'no_referencia' => null,
            'asesor' => 'Carlos Pérez',
            'total_plazos' => 0,
            'no_plazo' => 0,
            'condicion_pago' => '30 días',
            'fecha_contabilizacion' => '2026-09-01',
            'fecha_vencimiento' => '2026-10-01',
            'fecha_documento' => '2026-09-01',
            'dias' => 14,
            'limite_credito' => '50000000.00',
            'importe_original' => '1500000.00',
            'base_gravable' => null,
            'saldo_base' => null,
            'saldo_vencido' => '1000000.50',
            'valor_abonado' => '0.00',
            'sin_vencer' => '0.00',
            'valor_cheques' => '0.00',
            'd_0_30' => '1000000.50',
            'd_31_60' => '0.00',
            'd_61_90' => '0.00',
            'd_91_120' => '0.00',
            'd_121_150' => '0.00',
            'd_151_180' => '0.00',
            'd_mas_180' => '0.00',
            'saldo' => '1000000.50',
            'rango' => '0-30',
        ])]);
});

it('matches headers trimmed, in any case and with spaces collapsed, and lists the unknown ones', function () {
    $headers = ['  CÓDIGO SN ', 'nit', 'Documento', 'Fecha   vencimiento', 'SALDO', 'Comentario del analista', 'Saldo'];
    $row = ['  CÓDIGO SN ' => 'C009', 'nit' => '800', 'Documento' => 'F-1', 'Fecha   vencimiento' => '2026-10-20', 'SALDO' => 500.0, 'Comentario del analista' => 'ojo', 'Saldo' => 1.0];

    $preview = ingestaPrepare(IngestaWorkbooks::sap([$row], $headers, before: []));
    $built = $preview->batch->rows[0];

    expect($preview->errors)->toBe([])
        ->and($preview->ignoredHeaders)->toBe(['Comentario del analista', 'Saldo'])
        ->and([$built['codigo_sn'], $built['nit'], $built['documento'], $built['saldo'], $built['dias'], $built['rango'], $built['sin_vencer']])
        ->toBe(['C009', '800', 'F-1', '500.00', -5, 'Sin vencer', '500.00']);
});

it('reads the Datos sheet when there is one', function () {
    $path = IngestaWorkbooks::write([
        'Resumen' => IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Código SN' => 'RESUMEN'])]),
        'Datos' => IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Código SN' => 'DATOS'])]),
    ]);

    expect(array_column(ingestaPrepare($path)->batch->rows, 'codigo_sn'))->toBe(['DATOS']);
});

it('reads the first sheet when none is called Datos', function () {
    $path = IngestaWorkbooks::write([
        'Cartera' => IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Código SN' => 'PRIMERA'])]),
        'Otra' => IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Código SN' => 'SEGUNDA'])]),
    ]);

    expect(array_column(ingestaPrepare($path)->batch->rows, 'codigo_sn'))->toBe(['PRIMERA']);
});

it('converts Excel serial dates and date cells', function () {
    $path = IngestaWorkbooks::sap([IngestaWorkbooks::sapRow([
        'Fecha contabilización' => 46266.0,
        'Fecha vencimiento' => new DateTimeImmutable('2026-10-01 00:00:00'),
        'Fecha documento' => '01/09/2026',
    ])]);

    $row = ingestaPrepare($path)->batch->rows[0];

    expect([$row['fecha_contabilizacion'], $row['fecha_vencimiento'], $row['fecha_documento'], $row['dias']])
        ->toBe(['2026-09-01', '2026-10-01', '2026-09-01', 14]);
});

it('reads amounts written as Colombian text', function () {
    $path = IngestaWorkbooks::sap([IngestaWorkbooks::sapRow([
        'Saldo' => '$ 1.234.567,89',
        'Importe original' => "$\u{00A0}2.000.000",
        'Límite crédito' => '50.000.000,00',
        'Valor abonado' => '-1.500,5',
    ])]);

    $row = ingestaPrepare($path)->batch->rows[0];

    expect([$row['saldo'], $row['d_0_30'], $row['importe_original'], $row['limite_credito'], $row['valor_abonado']])
        ->toBe(['1234567.89', '1234567.89', '2000000.00', '50000000.00', '-1500.50']);
});

it('recomputes days, buckets and range from the cutoff, not from the Excel', function () {
    $path = IngestaWorkbooks::sap([
        IngestaWorkbooks::sapRow(['Documento' => 'F-1', 'Fecha vencimiento' => '2026-10-20', 'Saldo' => 100.0, 'Días' => 3.0, 'Rango' => '0-30']),
        IngestaWorkbooks::sapRow(['Documento' => 'F-2', 'Fecha vencimiento' => '2026-08-01', 'Saldo' => 200.0]),
        IngestaWorkbooks::sapRow(['Documento' => 'F-3', 'Fecha vencimiento' => '2026-01-01', 'Saldo' => -50.0]),
    ]);

    $rows = ingestaPrepare($path)->batch->rows;
    $pick = fn (array $row): array => [$row['dias'], $row['rango'], $row['sin_vencer'], $row['d_61_90'], $row['d_mas_180'], $row['saldo_vencido']];

    expect(array_map($pick, $rows))->toBe([
        [-5, 'Sin vencer', '100.00', '0.00', '0.00', '100.00'],
        [75, '61-90', '0.00', '200.00', '0.00', '200.00'],
        [287, '> 180', '0.00', '0.00', '-50.00', '-50.00'],
    ]);
});

it('skips empty rows and lines without client and document, like the totals line', function () {
    $path = IngestaWorkbooks::write(['Datos' => [
        ...IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Documento' => 'F-1'])]),
        [],
        ...array_slice(IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Documento' => 'F-2'])]), 1),
        array_map(fn (string $header): mixed => $header === 'Saldo' ? 2000001.0 : ($header === 'Nombre SN' ? 'Total' : null), IngestaWorkbooks::SAP_HEADERS),
    ]]);

    $preview = ingestaPrepare($path);

    expect(array_column($preview->batch->rows, 'documento'))->toBe(['F-1', 'F-2'])
        ->and($preview->lines)->toBe([2, 4]);
});

it('fills the advisor code from the latest asesores load by name', function () {
    app(IngestaSink::class)->receive(IngestaRows::batch('asesores-SGX-1', [
        IngestaRows::advisor(14, ['nombre' => ' CARLOS PÉREZ ', 'email' => 'carlos@segurex.com']),
        IngestaRows::advisor(15, ['nombre' => 'Luz Gómez']),
    ], Dataset::Advisors, cutoffDate: '2026-10-14'));
    app(IngestaSink::class)->receive(IngestaRows::batch('asesores-SDC-1', [
        IngestaRows::advisor(99, ['nombre' => 'Carlos Pérez']),
    ], Dataset::Advisors, CompanyCode::SDC, '2026-10-14'));

    $row = ingestaPrepare(IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]))->batch->rows[0];

    expect([$row['slp_code'], $row['email_asesor']])->toBe([14, 'carlos@segurex.com']);
});

it('falls back to the advisor of the client in the latest cartera load', function () {
    app(IngestaSink::class)->receive(IngestaRows::batch('cartera-SGX-1', [
        IngestaRows::cartera(['codigo_sn' => 'C001', 'slp_code' => 21, 'email_asesor' => 'luz@segurex.com']),
    ], Dataset::Receivables, cutoffDate: '2026-10-14'));

    $preview = ingestaPrepare(IngestaWorkbooks::sap([
        IngestaWorkbooks::sapRow(['Empleado ventas / compras' => 'Asesor desconocido']),
        IngestaWorkbooks::sapRow(['Código SN' => 'C777', 'Documento' => 'F-2', 'Empleado ventas / compras' => null]),
    ]));

    expect(array_map(fn (array $row): array => [$row['slp_code'], $row['email_asesor']], $preview->batch->rows))
        ->toBe([[21, 'luz@segurex.com'], [null, null]])
        ->and($preview->withoutAdvisorCount())->toBe(1);
});

it('previews the upload with its figures, the advisor warning and the first 20 rows', function () {
    $rows = array_map(fn (int $i): array => IngestaWorkbooks::sapRow([
        'Código SN' => 'C'.($i % 3),
        'Documento' => "F-{$i}",
        'Saldo' => 1000.0,
        'Fecha vencimiento' => $i % 2 === 0 ? '2026-10-01' : '2026-10-30',
    ]), range(1, 22));
    $headers = [...IngestaWorkbooks::SAP_HEADERS, 'Observaciones'];

    $response = ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), IngestaWorkbooks::sap($rows, $headers));

    $response->assertOk()
        ->assertSeeTextInOrder([
            'Vista previa de la carga',
            'Hay filas sin código de asesor', '22 filas no tienen código de asesor',
            'Columnas que no se cargan', '«Observaciones»',
            'Segurex Latam', '15/10/2026', '22', '3', "$\u{a0}22.000", "$\u{a0}11.000",
            'Primeras 20 filas',
        ])
        ->assertDontSeeText('El archivo tiene errores');

    expect(substr_count((string) $response->getContent(), '<tr data-row>'))->toBe(20)
        ->and(ingestaPendingTokens())->toHaveCount(1)
        ->and(Storage::disk('local')->allFiles())->toBe(['ingesta/manual/'.ingestaPendingTokens()[0].'.xlsx'])
        ->and(IngestaLoad::query()->count())->toBe(0);

    $response->assertDontSee('disabled="disabled"', false);
});

it('refuses a workbook without the required columns and keeps nothing', function () {
    $headers = array_values(array_diff(IngestaWorkbooks::SAP_HEADERS, ['Documento', 'Saldo']));

    ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()], $headers))
        ->assertRedirect(route('ingesta.manual.create'))
        ->assertSessionHasErrors(['archivo' => 'Al archivo le faltan estas columnas: «Documento», «Saldo».'])
        ->assertSessionHasInput('fecha_corte', '2026-10-15');

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(ingestaPendingTokens())->toBe([]);
});

it('refuses a workbook whose header row is not in the first 20 rows', function () {
    $before = array_fill(0, 20, ['relleno']);

    ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()], before: $before))
        ->assertSessionHasErrors(['archivo' => 'No encontramos la fila de títulos: debe estar en las primeras 20 filas y tener las columnas «Código SN» y «Nit».']);

    // Reading stopped halfway, and the file is still deleted.
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a workbook with more data rows than ingesta.max_rows and keeps nothing', function () {
    config(['ingesta.max_rows' => 2]);

    ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), IngestaWorkbooks::sap([
        IngestaWorkbooks::sapRow(['Documento' => 'F-1']),
        IngestaWorkbooks::sapRow(['Documento' => 'F-2']),
        IngestaWorkbooks::sapRow(['Documento' => 'F-3']),
    ]))
        ->assertRedirect(route('ingesta.manual.create'))
        ->assertSessionHasErrors(['archivo' => 'El archivo tiene más de 2 filas. Revisa que sea el informe de cartera.']);

    expect(Storage::disk('local')->allFiles())->toBe([])
        ->and(ingestaPendingTokens())->toBe([]);
});

it('previews a workbook with exactly ingesta.max_rows data rows', function () {
    config(['ingesta.max_rows' => 2]);

    ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), IngestaWorkbooks::sap([
        IngestaWorkbooks::sapRow(['Documento' => 'F-1']),
        IngestaWorkbooks::sapRow(['Documento' => 'F-2']),
    ]))->assertOk()->assertSessionHasNoErrors();
});

it('stops reading a workbook after ingesta.max_rows plus the header search rows, empty ones included', function () {
    config(['ingesta.max_rows' => 2]);
    $table = IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Documento' => 'F-1'])]);
    $last = IngestaWorkbooks::table([IngestaWorkbooks::sapRow(['Documento' => 'F-2'])])[1];
    // Header, one row, 25 empty rows and a second row on line 28: past 2 + 20 lines.
    $path = IngestaWorkbooks::write(['Datos' => [...$table, ...array_fill(0, 25, [null]), $last]]);

    ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), $path)
        ->assertSessionHasErrors(['archivo' => 'El archivo tiene más de 2 filas. Revisa que sea el informe de cartera.']);
});

it('refuses a file that is not a workbook', function () {
    $path = tempnam(sys_get_temp_dir(), 'ingesta-fake-');
    file_put_contents($path, 'no soy un excel');

    try {
        expect(fn () => ingestaPrepare($path))->toThrow(UnreadableWorkbook::class, 'No pudimos leer el archivo. Revisa que sea un Excel .xlsx sin contraseña.');
    } finally {
        @unlink($path);
    }
});

it('lists the validation errors by Excel row and disables the confirmation', function () {
    $path = IngestaWorkbooks::sap([
        IngestaWorkbooks::sapRow(['Documento' => 'F-1']),
        IngestaWorkbooks::sapRow(['Documento' => null]),
        IngestaWorkbooks::sapRow(['Documento' => 'F-3', 'Fecha vencimiento' => 'pronto', 'Saldo' => 'mucho']),
    ]);

    $response = ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), $path);

    $response->assertOk()
        ->assertSeeText('El archivo tiene errores')
        ->assertSeeTextInOrder([
            'Fila 5 de Excel, columna «Documento»', 'La columna documento es obligatoria.',
            'Fila 6 de Excel, columna «Fecha vencimiento»', 'La columna fecha_vencimiento debe ser una fecha real con formato AAAA-MM-DD.',
            'Fila 6 de Excel, columna «Días»', 'La columna dias es obligatoria.',
            'Fila 6 de Excel, columna «Saldo»', 'La columna saldo debe ser un número con punto decimal',
        ])
        ->assertSee('disabled="disabled"', false);

    expect(IngestaLoad::query()->count())->toBe(0);
});

it('confirms the upload as an accepted manual load', function () {
    $user = IngestaUsers::create(Role::PortfolioManager);
    $path = IngestaWorkbooks::sap([IngestaWorkbooks::sapRow(), IngestaWorkbooks::sapRow(['Documento' => 'F-2', 'Saldo' => 250000.0, 'Fecha vencimiento' => '2026-10-30'])]);
    ingestaUpload($user, $path)->assertOk();
    $token = ingestaPendingTokens()[0];

    $this->actingAs($user)->post("/ingesta/carga-manual/{$token}/confirmar")
        ->assertRedirect(route('ingesta.index'))
        ->assertSessionHas('status', 'Cargaste la cartera de Segurex Latam con corte 15/10/2026: 2 filas.');

    $load = IngestaLoad::query()->sole();

    expect($load->status)->toBe(LoadStatus::Accepted)
        ->and($load->source)->toBe(LoadSource::Manual)
        ->and($load->dataset)->toBe(Dataset::Receivables)
        ->and($load->company)->toBe(CompanyCode::SGX)
        ->and($load->cutoff_date->format('Y-m-d'))->toBe('2026-10-15')
        ->and($load->received_by)->toBe($user->id)
        ->and($load->row_count)->toBe(2)
        ->and($load->total)->toBe('1250000.50')
        ->and($load->overdue)->toBe('1000000.50')
        ->and($load->batch_id)->toBe('manual-cartera-SGX-2026-10-15-'.substr((string) hash_file('sha256', $path), 0, 16))
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(ingestaPendingTokens())->toBe([]);

    Event::assertDispatchedTimes(BatchAccepted::class, 1);
    $this->audit->assertRecorded('ingesta.load.uploaded', fn (AuditEntry $entry): bool => $entry->subjectType === 'ingesta_load'
        && $entry->subjectId === $load->id
        && $entry->context === ['company' => 'SGX', 'cutoff_date' => '2026-10-15', 'rows' => 2]);
});

it('registers the same file and cutoff only once', function () {
    $user = IngestaUsers::create(Role::PortfolioAnalyst);
    $path = IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]);

    ingestaUpload($user, $path)->assertOk();
    $this->actingAs($user)->post('/ingesta/carga-manual/'.ingestaPendingTokens()[0].'/confirmar')->assertSessionHas('status');

    ingestaUpload($user, $path)->assertOk();
    $this->actingAs($user)->post('/ingesta/carga-manual/'.ingestaPendingTokens()[0].'/confirmar')
        ->assertRedirect(route('ingesta.index'))
        ->assertSessionHas('status', 'Esta carga ya estaba registrada.');

    expect(IngestaLoad::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($this->audit->entries())->toHaveCount(1);
    Event::assertDispatchedTimes(BatchAccepted::class, 1);
});

it('discards the upload', function () {
    $user = IngestaUsers::create(Role::PortfolioAnalyst);
    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]))->assertOk();

    $this->actingAs($user)->post('/ingesta/carga-manual/'.ingestaPendingTokens()[0].'/descartar')
        ->assertRedirect(route('ingesta.index'))
        ->assertSessionHas('status', 'Descartaste la carga; no se guardó nada.');

    expect(IngestaLoad::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(ingestaPendingTokens())->toBe([]);
});

it('keeps an upload to the user who made it', function () {
    ingestaUpload(IngestaUsers::create(Role::PortfolioAnalyst), IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]))->assertOk();
    $token = ingestaPendingTokens()[0];
    $other = IngestaUsers::create(Role::PortfolioManager, 'Otra persona');

    $this->actingAs($other)->post("/ingesta/carga-manual/{$token}/confirmar")->assertNotFound();
    $this->actingAs($other)->post("/ingesta/carga-manual/{$token}/descartar")->assertNotFound();

    expect(IngestaLoad::query()->count())->toBe(0)
        ->and(ingestaPendingTokens())->toBe([$token])
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
});

it('answers 404 for an unknown token', function () {
    $user = IngestaUsers::create(Role::PortfolioAnalyst);

    $this->actingAs($user)->post('/ingesta/carga-manual/0193a8c4-5d1e-7c3a-9b2f-1a2b3c4d5e6f/confirmar')->assertNotFound();
    $this->actingAs($user)->post('/ingesta/carga-manual/no-es-un-token/confirmar')->assertNotFound();
});

it('asks for the file again when it changed or disappeared since the preview', function () {
    $user = IngestaUsers::create(Role::PortfolioAnalyst);
    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]))->assertOk();
    $token = ingestaPendingTokens()[0];
    Storage::disk('local')->put("ingesta/manual/{$token}.xlsx", 'otro contenido');

    $this->actingAs($user)->post("/ingesta/carga-manual/{$token}/confirmar")
        ->assertRedirect(route('ingesta.manual.create'))
        ->assertSessionHas('error', 'La vista previa ya no está disponible. Sube el archivo de nuevo.');

    expect(IngestaLoad::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(ingestaPendingTokens())->toBe([]);
});

it('reports a rejected confirmation in the history', function () {
    $user = IngestaUsers::create(Role::PortfolioAnalyst);
    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow(['Documento' => null])]))->assertOk();

    $this->actingAs($user)->post('/ingesta/carga-manual/'.ingestaPendingTokens()[0].'/confirmar')
        ->assertRedirect(route('ingesta.index'))
        ->assertSessionHas('error', 'La carga se rechazó: La columna documento es obligatoria.');

    expect(IngestaLoad::query()->sole()->status)->toBe(LoadStatus::Rejected)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    Event::assertNotDispatched(BatchAccepted::class);
});

it('records nothing in the audit log when no audit logger is bound', function () {
    $this->app->forgetInstance(AuditLogger::class);
    unset($this->app[AuditLogger::class]);
    $user = IngestaUsers::create(Role::PortfolioAnalyst);

    ingestaUpload($user, IngestaWorkbooks::sap([IngestaWorkbooks::sapRow()]))->assertOk();
    $this->actingAs($user)->post('/ingesta/carga-manual/'.ingestaPendingTokens()[0].'/confirmar')->assertSessionHas('status');

    expect(IngestaLoad::query()->count())->toBe(1)
        ->and($this->audit->entries())->toBe([]);
});
