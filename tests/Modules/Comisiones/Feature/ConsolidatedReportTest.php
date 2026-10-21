<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The consolidated report (§7.6, §8.1) on screen, in Excel and in PDF: the
| regular team with 81.1 and 85.1, the codes settled apart, the channel
| directors and the summary per person for payroll.
|
| July 2026: code 14 sells 69,915,134 on a budget of 77,241,857 (524,364);
| code 85 collects 5,548,738 (83,231, and 55,487 for 85.1); code 81 sells
| 20,000,000 (70,000, and 20,000 for 81.1); code 84 sells 10,000,000
| (100,000). Distribución pays its director 0.2 % of 69,915,134 (139,830)
| and Construcción institucional 1 % of 5,548,738 (55,487).
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();

    $this->sap->send(Dataset::Sales, [
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['linea' => 1, 'total' => '9915134.00']),
        SapBatches::sale(['doc_entry' => 5100, 'numero_documento' => 8100, 'slp_code' => 81, 'vendedor' => 'ANDREA HINCAPIE', 'total' => '20000000.00']),
        SapBatches::sale(['doc_entry' => 5200, 'numero_documento' => 8200, 'slp_code' => 84, 'vendedor' => 'JESSIKA QUINTERO', 'total' => '10000000.00']),
    ]);
    $payment = fn (int $line, string $paid) => SapBatches::payment([
        'doc_entry_pago' => 7100 + $line, 'slp_code' => 85, 'vendedor' => 'LILIAM HERRERA', 'fecha_pago' => '2026-07-2'.$line, 'valor_pagado_sin_iva' => $paid,
    ]);
    $this->sap->send(Dataset::Collections, [
        $payment(1, '2000000.00'),
        $payment(2, '1500000.00'),
        $payment(3, '1048738.00'),
        $payment(4, '1000000.00'),
    ]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
});

/**
 * The rows of the first sheet of a downloaded workbook.
 *
 * @return list<list<mixed>>
 */
function comisionesWorkbookRows(TestResponse $response): array
{
    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();

    return $rows;
}

it('serves the consolidated report and its files under the period', function () {
    expect(route('comisiones.periodos.consolidado', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/consolidado')
        ->and(route('comisiones.periodos.consolidado.xlsx', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/consolidado.xlsx')
        ->and(route('comisiones.periodos.consolidado.pdf', '2026-07', absolute: false))->toBe('/comisiones/periodos/2026-07/consolidado.pdf');

    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-06/consolidado')->assertNotFound();
    $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-06/consolidado.xlsx')->assertNotFound();
});

it('forbids the report and its files to roles without comisiones.view', function (array $roles) {
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get('/comisiones/periodos/2026-07/consolidado')->assertForbidden();
    $this->actingAs($user)->get('/comisiones/periodos/2026-07/consolidado.xlsx')->assertForbidden();
    $this->actingAs($user)->get('/comisiones/periodos/2026-07/consolidado.pdf')->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('shows the three sections and the summary per person', function () {
    $response = $this->actingAs(ComisionesUsers::viewer())->get('/comisiones/periodos/2026-07/consolidado')->assertOk();

    $response->assertSeeText('Informe de comisiones — período calculado del 01 al 31 de julio de 2026')
        ->assertSeeText('Borrador')
        ->assertSeeText('Equipo comercial regular')
        ->assertSeeText('Comisiones especiales')
        ->assertSeeText('Directores de canal')
        ->assertSeeText('Resumen por persona')
        // Section 1: codes with activity, the derived rows and the total.
        ->assertSee('data-row="14"', false)
        ->assertSee('data-row="85"', false)
        ->assertSee('data-row="81.1"', false)
        ->assertSee('data-row="85.1"', false)
        ->assertDontSee('data-row="15"', false)
        ->assertSeeText(Money::format('524364'))
        ->assertSeeText(Money::format('83231'))
        ->assertSeeText(Money::format('683082'))
        // Section 2: the codes settled apart.
        ->assertSee('data-separate="81"', false)
        ->assertSee('data-separate="84"', false)
        ->assertSeeText(Money::format('100000'))
        // Section 3: the director teams.
        ->assertSee('data-team="distribucion"', false)
        ->assertSeeText(Money::format('139830'))
        // Annex: per person.
        ->assertSee('data-person="sergio-caballero"', false)
        ->assertSeeText(Money::format('75487'))
        ->assertSeeText(Money::format('1048399'))
        // Drill-down to the code.
        ->assertSee('href="'.route('comisiones.periodos.codigo', ['periodo' => '2026-07', 'codigo' => 14]).'"', false);

    expect(preg_match('/\p{Lu}{4,}/u', strip_tags($response->getContent())))->toBe(0);
});

it('downloads the report as an Excel workbook with amounts as numbers, and audits it', function () {
    $response = $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07/consolidado.xlsx')
        ->assertOk()
        ->assertHeader('Content-Type', SpreadsheetDownload::MIME_TYPE)
        ->assertDownload('Consolidado_Comisiones_2026-07_v1.xlsx');

    $rows = comisionesWorkbookRows($response);

    expect($rows[0][0])->toBe('Informe de comisiones — período calculado del 01 al 31 de julio de 2026')
        ->and($rows[1][0])->toBe('Versión 1 · revisión 1 · Borrador');

    $code14 = collect($rows)->first(fn (array $row) => ($row[0] ?? null) === '14');
    $total = collect($rows)->first(fn (array $row) => ($row[0] ?? null) === 'Total equipo regular');

    expect($code14[1])->toBe('Mónica Rivera')
        ->and($code14[2])->toEqual(524364)
        ->and($total[7])->toEqual(683082)
        ->and(collect($rows)->contains(fn (array $row) => ($row[0] ?? null) === '81.1'))->toBeTrue();

    $this->fakes->audit->assertRecorded('comisiones.export.downloaded', fn (AuditEntry $entry) => $entry->context['formato'] === 'xlsx'
        && $entry->context['version'] === 1);
    expect(AuditRecord::query()->where('evento', 'comisiones.export.downloaded')->where('user_id', $this->approver->id)->exists())->toBeTrue();
});

it('downloads the report as a letter landscape PDF, and audits it', function () {
    $response = $this->actingAs($this->analyst)->get('/comisiones/periodos/2026-07/consolidado.pdf')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('Consolidado_Comisiones_2026-07_v1.pdf');

    // Letter landscape: 792 × 612 points.
    expect(str_starts_with((string) $response->getContent(), '%PDF'))->toBeTrue()
        ->and((string) $response->getContent())->toContain('792.000 612.000');
    $this->fakes->audit->assertRecorded('comisiones.export.downloaded', fn (AuditEntry $entry) => $entry->context['formato'] === 'pdf');
});

it('labels an approved version that is not closed yet', function () {
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07/consolidado')->assertOk()
        ->assertSeeText('Aprobada (sin cerrar)');

    $rows = comisionesWorkbookRows($this->actingAs($this->approver)->get('/comisiones/periodos/2026-07/consolidado.xlsx'));

    expect($rows[1][0])->toBe('Versión 1 · revisión 1 · Aprobada (sin cerrar)');
});
