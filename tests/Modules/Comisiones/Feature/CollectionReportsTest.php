<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Domain\Shared\Money as DomainMoney;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\DocumentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Report\CollectionReport;
use App\Modules\Comisiones\Infrastructure\Report\CollectionReports;
use App\Modules\Comisiones\Infrastructure\Report\PdfRenderer;
use App\Modules\Comisiones\Infrastructure\Report\ReportArchive;
use App\Modules\Comisiones\Infrastructure\Report\ReportMismatch;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\AugustCollections;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The collection report of each seller (§7.7, §8.2, §9.3, §10): one PDF per
| code with collections in a closed version, plus 85.1, stored once with its
| sha256, downloaded one by one or in a ZIP, every step audited.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();

    AugustCollections::stage($this->sap);
});

function augustReports(): string
{
    return '/comisiones/periodos/'.AugustCollections::PERIOD.'/informes';
}

/**
 * The collection reports of the latest version of August.
 *
 * @return array<string, CollectionReport> By reference.
 */
function augustCollectionReports(): array
{
    $settlement = app(EloquentSettlements::class)->latest(Period::fromString(AugustCollections::PERIOD));
    $reports = [];

    foreach (app(CollectionReports::class)->of($settlement) as $report) {
        $reports[$report->reference] = $report;
    }

    return $reports;
}

function augustReportHtml(CollectionReport $report): string
{
    return view('comisiones::pdf.recaudo', [
        'report' => $report,
        'palette' => app(CurrentCompany::class)->of(CompanyCode::SGX)->palette,
        'generatedAt' => CarbonImmutable::now(),
    ])->render();
}

/**
 * The visible text of a report, ASCII whitespace collapsed (the no-break
 * space of the amounts stays).
 */
function augustReportText(string $html): string
{
    return (string) preg_replace('/[ \t\r\n]+/', ' ', strip_tags($html));
}

/**
 * The PDF the collection reports renderer draws from a page that runs a script and
 * shows a local image inside the project but outside the dompdf fonts.
 */
function comisionesPdfProbe(): string
{
    $directory = storage_path('framework/testing/'.uniqid('comisiones-pdf-probe-'));
    File::ensureDirectoryExists($directory);
    $image = $directory.DIRECTORY_SEPARATOR.'probe.png';
    file_put_contents($image, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));
    file_put_contents($directory.DIRECTORY_SEPARATOR.'probe.blade.php', '<html><body><img src="{{ $image }}" width="40" height="40"><script type="text/javascript">app.alert("probe");</script></body></html>');
    view()->addNamespace('comisiones-pdf-probe', $directory);

    try {
        return app(PdfRenderer::class)->render('comisiones-pdf-probe::probe', ['image' => $image]);
    } finally {
        File::deleteDirectory($directory);
    }
}

it('serves the reports under the period', function () {
    expect(route('comisiones.periodos.informes.index', '2026-08', absolute: false))->toBe('/comisiones/periodos/2026-08/informes')
        ->and(route('comisiones.periodos.informes.generar', '2026-08', absolute: false))->toBe('/comisiones/periodos/2026-08/informes/generar')
        ->and(route('comisiones.periodos.informes.pdf', ['periodo' => '2026-08', 'referencia' => '85.1'], absolute: false))->toBe('/comisiones/periodos/2026-08/informes/85.1.pdf')
        ->and(route('comisiones.periodos.informes.zip', '2026-08', absolute: false))->toBe('/comisiones/periodos/2026-08/informes.zip');
});

it('forbids the reports to roles without comisiones.reports', function (array $roles) {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get(augustReports())->assertForbidden();
    $this->actingAs($user)->post(augustReports().'/generar')->assertForbidden();
    $this->actingAs($user)->get(augustReports().'/14.pdf')->assertForbidden();
    $this->actingAs($user)->get(augustReports().'.zip')->assertForbidden();

    expect(DocumentRecord::query()->count())->toBe(0);
})->with([
    'consulta' => [['comisiones_consulta']],
    ...ComisionesUsers::notViewers(),
]);

it('explains that only a closed version has reports', function (string $step) {
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-08/calcular');

    if ($step === 'aprobada') {
        $revision = (int) SettlementRecord::query()->where('periodo', '2026-08')->value('revision');
        $this->actingAs($this->approver)->post('/comisiones/periodos/2026-08/aprobar', ['revision' => $revision]);
    }

    expect(SettlementRecord::query()->where('periodo', '2026-08')->value('estado'))->toBe($step);

    $this->actingAs($this->analyst)->get(augustReports())->assertOk()
        ->assertSeeText('Los informes se generan cuando la liquidación está cerrada.')
        ->assertDontSee('data-report="14"', false);

    $this->actingAs($this->analyst)->post(augustReports().'/generar')
        ->assertRedirect(augustReports())
        ->assertSessionHas('error', 'Los informes se generan cuando la liquidación está cerrada.');
    $this->actingAs($this->analyst)->get(augustReports().'/14.pdf')->assertRedirect(augustReports())->assertSessionHas('error');
    $this->actingAs($this->analyst)->get(augustReports().'.zip')->assertRedirect(augustReports())->assertSessionHas('error');

    expect(DocumentRecord::query()->count())->toBe(0);
})->with(['borrador', 'aprobada']);

it('answers 404 for a period never calculated and for a reference without a report', function () {
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-05/informes')->assertOk()
        ->assertSeeText('Los informes se generan cuando la liquidación está cerrada.');
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-05/informes/generar')->assertNotFound();

    AugustCollections::close($this, $this->analyst, $this->approver);

    // Code 50 sold but collected nothing: no report.
    $this->actingAs($this->analyst)->get(augustReports().'/50.pdf')->assertNotFound();
    $this->actingAs($this->analyst)->get(augustReports().'/99.pdf')->assertNotFound();
});

it('lists one report per code with collections and 85.1, pending until generated', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);

    $response = $this->actingAs($this->approver)->get(augustReports())->assertOk()
        ->assertSee('data-report="14"', false)
        ->assertSee('data-report="85"', false)
        ->assertSee('data-report="85.1"', false)
        ->assertDontSee('data-report="50"', false)
        ->assertSeeText('Mónica Rivera')
        ->assertSeeText('Sergio Caballero')
        ->assertSeeText(Money::format('13661706'))
        ->assertSeeText(Money::format('94585'))
        ->assertSeeText(Money::format('83231'))
        ->assertSeeText(Money::format('55487'))
        ->assertSeeText('Cuadra')
        ->assertSee('data-report="14" data-state="pendiente"', false)
        // Nothing to download until it is generated.
        ->assertDontSee(augustReports().'/14.pdf', false)
        ->assertDontSee(augustReports().'.zip', false)
        ->assertSeeText('Generar los informes pendientes');

    expect(preg_match('/\p{Lu}{4,}/u', strip_tags($response->getContent())))->toBe(0);
});

it('gives the batch more time without ever shortening a longer or unlimited limit', function (string $before, string $after) {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $original = ini_get('max_execution_time');
    ini_set('max_execution_time', $before);

    try {
        $this->actingAs($this->approver)->post(augustReports().'/generar')->assertSessionHas('status');
        $limit = ini_get('max_execution_time');
    } finally {
        // Back to what the suite had: a limit left here would stop the rest of the run.
        set_time_limit((int) $original);
    }

    expect($limit)->toBe($after);
})->with([
    'unlimited, as the CLI runs' => ['0', '0'],
    'the 30 seconds of shared hosting' => ['30', '180'],
    'already longer' => ['600', '600'],
]);

it('generates and stores every missing report of the closed version and its payroll file, once', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);

    $this->actingAs($this->approver)->post(augustReports().'/generar')
        ->assertRedirect(augustReports())
        ->assertSessionHas('status');

    $documents = DocumentRecord::query()->where('tipo', 'recaudo')->orderBy('id')->get();
    $settlementId = SettlementRecord::query()->where('periodo', '2026-08')->value('id');

    expect($documents)->toHaveCount(3)
        ->and($documents->pluck('referencia')->all())->toBe(['14', '85', '85.1'])
        ->and($documents->pluck('archivo')->all())->toBe([
            'Informe_Recaudo_14_Monica_Rivera.pdf',
            'Informe_Recaudo_85_Liliam_Herrera.pdf',
            'Informe_Recaudo_85.1_Sergio_Caballero.pdf',
        ])
        ->and($documents->pluck('tipo')->unique()->all())->toBe(['recaudo'])
        ->and($documents->pluck('liquidacion_id')->unique()->all())->toBe([$settlementId])
        ->and($documents->pluck('generado_por')->unique()->all())->toBe([$this->approver->id]);

    foreach ($documents as $document) {
        expect($document->ruta)->toBe('comisiones/informes/2026-08/v1/'.$document->archivo);
        Storage::disk('local')->assertExists($document->ruta);
        $bytes = Storage::disk('local')->get($document->ruta);
        expect(str_starts_with($bytes, '%PDF'))->toBeTrue()
            ->and(hash('sha256', $bytes))->toBe($document->sha256)
            ->and(strlen($bytes))->toBe($document->bytes);
    }

    expect(DocumentRecord::query()->where('tipo', 'nomina')->sole()->generado_por)->toBe($this->approver->id);

    $generated = AuditRecord::query()->where('evento', 'comisiones.report.generated')->orderBy('id')->get();
    expect($generated)->toHaveCount(4)
        ->and($generated->first()->periodo)->toBe('2026-08')
        ->and($generated->first()->contexto)->toMatchArray(['version' => 1, 'referencia' => '14', 'sha256' => $documents->first()->sha256]);

    // A second batch changes nothing: stored reports are immutable.
    $this->travel(1)->hours();
    $this->actingAs($this->analyst)->post(augustReports().'/generar')->assertRedirect(augustReports());

    expect(DocumentRecord::query()->count())->toBe(4)
        ->and(DocumentRecord::query()->where('tipo', 'recaudo')->orderBy('id')->pluck('sha256')->all())->toBe($documents->pluck('sha256')->all())
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.generated')->count())->toBe(4);

    $this->actingAs($this->approver)->get(augustReports())->assertOk()
        ->assertSeeText('Beto Ruiz')
        ->assertSeeText(substr($documents->first()->sha256, 0, 12))
        ->assertSee('data-report="14" data-state="generado"', false)
        ->assertDontSee('data-state="pendiente"', false)
        ->assertSee(augustReports().'/14.pdf', false)
        ->assertSee(augustReports().'.zip', false);
});

it('downloads a stored report and audits the download', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $this->actingAs($this->approver)->post(augustReports().'/generar');
    $document = DocumentRecord::query()->where('referencia', '85.1')->firstOrFail();

    $response = $this->actingAs($this->analyst)->get(augustReports().'/85.1.pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('Informe_Recaudo_85.1_Sergio_Caballero.pdf');

    expect(hash('sha256', $response->streamedContent()))->toBe($document->sha256);

    $audit = AuditRecord::query()->where('evento', 'comisiones.report.downloaded')->sole();
    expect($audit->periodo)->toBe('2026-08')
        ->and($audit->user_id)->toBe($this->analyst->id)
        ->and($audit->contexto)->toMatchArray(['version' => 1, 'referencia' => '85.1', 'sha256' => $document->sha256]);
});

it('never generates a missing report on a download', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);

    $this->actingAs($this->analyst)->get(augustReports().'/14.pdf')
        ->assertRedirect(augustReports())
        ->assertSessionHas('error', 'Ese informe todavía no se ha generado. Pulsa «Generar los informes pendientes» primero.');

    expect(DocumentRecord::query()->count())->toBe(0)
        ->and(AuditRecord::query()->where('evento', 'like', 'comisiones.report.%')->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a stored report whose file changed or is missing', function (string $damage) {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $this->actingAs($this->approver)->post(augustReports().'/generar');
    $document = DocumentRecord::query()->where('referencia', '14')->firstOrFail();

    $damage === 'cambiado'
        ? Storage::disk('local')->put($document->ruta, '%PDF-1.7 otro contenido')
        : Storage::disk('local')->delete($document->ruta);

    $this->actingAs($this->approver)->get(augustReports().'/14.pdf')
        ->assertRedirect(augustReports())
        ->assertSessionHas('error');
    $this->actingAs($this->approver)->get(augustReports().'.zip')
        ->assertRedirect(augustReports())
        ->assertSessionHas('error');

    expect(AuditRecord::query()->where('evento', 'comisiones.report.downloaded')->count())->toBe(0)
        // Never regenerated over the stored one.
        ->and(DocumentRecord::query()->where('referencia', '14')->value('sha256'))->toBe($document->sha256);
})->with(['cambiado', 'borrado']);

it('downloads every report of the version in one ZIP', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $this->actingAs($this->approver)->post(augustReports().'/generar');

    $response = $this->actingAs($this->approver)->get(augustReports().'.zip')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertDownload('Informes_Recaudo_2026-08_v1.zip');

    $zip = new ZipArchive;
    expect($zip->open($response->baseResponse->getFile()->getPathname()))->toBeTrue();
    $names = [];
    for ($index = 0; $index < $zip->numFiles; $index++) {
        $names[] = $zip->getNameIndex($index);
    }

    expect($names)->toBe([
        'Informe_Recaudo_14_Monica_Rivera.pdf',
        'Informe_Recaudo_85_Liliam_Herrera.pdf',
        'Informe_Recaudo_85.1_Sergio_Caballero.pdf',
    ]);

    foreach (DocumentRecord::query()->where('tipo', 'recaudo')->get() as $document) {
        expect(hash('sha256', (string) $zip->getFromName($document->archivo)))->toBe($document->sha256);
    }

    $zip->close();

    expect(DocumentRecord::query()->count())->toBe(4)
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.downloaded')->sole()->contexto)
        ->toMatchArray(['version' => 1, 'referencia' => 'zip', 'archivo' => 'Informes_Recaudo_2026-08_v1.zip']);
});

it('never generates the reports a ZIP needs on its download', function (bool $partly) {
    AugustCollections::close($this, $this->analyst, $this->approver);

    if ($partly) {
        $settlement = app(EloquentSettlements::class)->latest(Period::fromString('2026-08'));
        app(ReportArchive::class)->collection($settlement, augustCollectionReports()['14'], $this->approver);
    }

    $this->actingAs($this->approver)->get(augustReports().'.zip')
        ->assertRedirect(augustReports())
        ->assertSessionHas('error', 'Ese informe todavía no se ha generado. Pulsa «Generar los informes pendientes» primero.');

    expect(DocumentRecord::query()->pluck('referencia')->all())->toBe($partly ? ['14'] : [])
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.generated')->count())->toBe($partly ? 1 : 0)
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.downloaded')->count())->toBe(0);
})->with(['none generated' => false, 'one generated' => true]);

it('draws the collection report of a code as §8.2 asks', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $report = augustCollectionReports()['14'];

    expect($report->fileName())->toBe('Informe_Recaudo_14_Monica_Rivera.pdf')
        ->and($report->transactions())->toBe(3)
        ->and($report->total()->amount())->toBe('94585')
        ->and($report->expected->amount())->toBe('94585')
        ->and($report->matches())->toBeTrue();

    $html = augustReportHtml($report);
    $text = augustReportText($html);

    expect($text)->toContain('Informe de comisión por recaudo')
        ->toContain('Comercial: Mónica Rivera')
        ->toContain('Período: agosto de 2026')
        ->toContain('Vendedor Código Factura Código cliente Razón social Fecha factura Fecha pago Importe original Días Valor pagado Comisión')
        // Ordered by payment date, the crossing marked.
        ->toMatch('/8225 .* 05\/08\/2026 .* 9098 .* 11\/08\/2026 .* 8001 .*\(cruce\) .* 20\/08\/2026/u')
        ->toContain('Ferretería El Tornillo SAS (cruce)')
        ->toContain(Money::format('7878.15'))
        ->toContain(Money::format('94585'))
        ->toContain(Money::format('13661706'))
        ->toContain('Número de transacciones: 3')
        ->not->toContain('tasa fija');
    expect($html)->toContain('display: table-header-group');
    expect(preg_match('/\p{Lu}{4,}/u', $text))->toBe(0);
});

it('draws the 85.1 report with its note and without days', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $report = augustCollectionReports()['85.1'];

    expect($report->fileName())->toBe('Informe_Recaudo_85.1_Sergio_Caballero.pdf')
        ->and($report->total()->amount())->toBe('55487')
        ->and($report->matches())->toBeTrue();

    $text = augustReportText(augustReportHtml($report));

    expect($text)->toContain('Comercial: Sergio Caballero')
        ->toContain('Comisión especial sobre el recaudo del código 85 (Liliam Herrera) — tasa fija 1,00 %, no aplican tramos de días')
        ->toContain('Número de transacciones: 4')
        ->toContain(Money::format('55487'))
        // Importe original (one token: its space is a no-break one), then «—» for the days.
        ->toMatch('/Constructora Andina SAS \d{2}\/\d{2}\/2026 \d{2}\/08\/2026 [^ ]+ — /u');
});

it('refuses to store a report whose total differs from the consolidated figure (§9.3)', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $settlement = app(EloquentSettlements::class)->latest(Period::fromString('2026-08'));
    $real = augustCollectionReports()['14'];
    $tampered = new CollectionReport(
        reference: $real->reference,
        seller: $real->seller,
        lineSeller: $real->lineSeller,
        period: $real->period,
        version: $real->version,
        lines: $real->lines,
        expected: DomainMoney::of('94000'),
        showsDays: true,
        note: null,
    );

    expect($tampered->matches())->toBeFalse()
        ->and($tampered->difference()->amount())->toBe('585');

    expect(fn () => app(ReportArchive::class)->collection($settlement, $tampered, $this->approver))
        ->toThrow(ReportMismatch::class);

    expect(DocumentRecord::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('generates the reports of about thirty codes well under two minutes (§11)', function () {
    $codes = [14, 15, 16, 17, 18, 21, 23, 32, 33, 34, 36, 41, 42, 43, 44, 50, 51, 52, 53, 61, 66, 67, 69, 81, 82, 84, 86, 90, 85];
    $rows = [];

    foreach ($codes as $index => $code) {
        foreach ([1, 2, 3] as $line) {
            $rows[] = SapBatches::payment([
                'doc_entry_pago' => 20000 + $index * 10 + $line,
                'numero_pago' => 40000 + $index * 10 + $line,
                'numero_factura' => 60000 + $index * 10 + $line,
                'slp_code' => $code,
                'vendedor' => "CODIGO {$code}",
                'fecha_pago' => '2026-08-1'.$line,
                'valor_pagado_sin_iva' => (string) (1000000 + $index * 1000 + $line).'.00',
            ]);
        }
    }

    $this->sap->send(Dataset::Collections, $rows, cutoff: '2026-08-31');
    AugustCollections::close($this, $this->analyst, $this->approver);

    $started = microtime(true);
    $this->actingAs($this->approver)->post(augustReports().'/generar')->assertRedirect(augustReports());
    $seconds = microtime(true) - $started;

    // Every code with collections plus 85.1.
    expect(DocumentRecord::query()->where('tipo', 'recaudo')->count())->toBe(count($codes) + 1)
        ->and($seconds)->toBeLessThan(120.0);
});

it('draws its PDFs without JavaScript nor files outside the dompdf fonts', function () {
    $pdf = comisionesPdfProbe();

    expect($pdf)->toStartWith('%PDF')
        ->not->toContain('/Subtype /Image')
        ->not->toContain('/JavaScript');
});
