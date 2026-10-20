<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Domain\Calculation\PersonSettlement;
use App\Modules\Comisiones\Domain\Shared\Money as DomainMoney;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\DocumentRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\AugustCollections;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The payroll file of a closed version (§3, §6 step 6, §8.1 annex): every
| person with each role they are paid for and their «valor pago nómina»,
| stored once like the PDFs and served from the stored copy.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    Storage::fake('local');
    $this->travelTo(CarbonImmutable::parse('2026-09-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();

    AugustCollections::stage(new SapBatches($this->fakes->ingesta));
});

const PAYROLL_URL = '/comisiones/periodos/2026-08/informes/nomina.xlsx';

/**
 * The rows of the first sheet of a streamed workbook.
 *
 * @return list<list<mixed>>
 */
function payrollRows(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'nomina-test-');
    file_put_contents($path, $bytes);
    $reader = new Reader;
    $reader->open($path);
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }

        break;
    }

    $reader->close();
    unlink($path);

    return $rows;
}

it('serves the payroll file under the reports of the period', function () {
    expect(route('comisiones.periodos.informes.nomina', '2026-08', absolute: false))->toBe(PAYROLL_URL);
});

it('forbids the payroll file to roles without comisiones.reports', function (array $roles) {
    AugustCollections::close($this, $this->analyst, $this->approver);

    $this->actingAs(ComisionesUsers::create($roles))->get(PAYROLL_URL)->assertForbidden();

    expect(DocumentRecord::query()->count())->toBe(0);
})->with([
    'consulta' => [['comisiones_consulta']],
    ...ComisionesUsers::notViewers(),
]);

it('refuses the payroll file of a version that is not closed', function () {
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-08/calcular');

    $this->actingAs($this->analyst)->get(PAYROLL_URL)
        ->assertRedirect('/comisiones/periodos/2026-08/informes')
        ->assertSessionHas('error', 'Los informes se generan cuando la liquidación está cerrada.');

    expect(DocumentRecord::query()->count())->toBe(0);
});

it('lists every person with each role and their payroll value, plus the grand total', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $result = app(EloquentSettlements::class)->latest(Period::fromString('2026-08'))->result;

    $response = $this->actingAs($this->approver)->get(PAYROLL_URL)
        ->assertOk()
        ->assertHeader('Content-Type', SpreadsheetDownload::MIME_TYPE)
        ->assertDownload('Nomina_Comisiones_2026-08_v1.xlsx');

    $rows = payrollRows($response->streamedContent());

    $row = fn (string $person, string $concept): ?array => collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === $person && ($row[3] ?? null) === $concept);

    expect($rows[0][0])->toBe('Archivo de nómina — agosto de 2026')
        ->and($rows[1][0])->toBe('Versión 1 · revisión 1 · Cerrada')
        // The reader skips the blank row under the version line.
        ->and($rows[2])->toBe(['Persona', 'Tipo', 'Referencia', 'Concepto', 'Valor'])
        // A role row and the person's total.
        ->and(array_slice($row('Mónica Rivera', 'Código 14 – Mónica Rivera'), 1, 2))->toBe(['Código', '14'])
        ->and($row('Mónica Rivera', 'Código 14 – Mónica Rivera')[4])->toEqual(94585)
        ->and(array_slice($row('Sergio Caballero', 'Sergio Caballero (recaudo)'), 1, 2))->toBe(['Especial', '85.1'])
        ->and($row('Sergio Caballero', 'Sergio Caballero (recaudo)')[4])->toEqual(55487)
        ->and($row('Sergio Caballero', 'Valor pago nómina')[4])->toEqual(55487);

    $totals = array_values(array_filter($rows, fn (array $row): bool => ($row[3] ?? null) === 'Valor pago nómina'));
    $grand = DomainMoney::sum(array_map(fn (PersonSettlement $person) => $person->total, $result->persons()));
    $last = end($rows);

    expect($totals)->toHaveCount(count($result->persons()))
        ->and($last[0])->toBe('Total nómina')
        ->and($last[4])->toEqual((float) $grand->toCents()->amount());
});

it('stores the payroll file once and serves the stored copy, audited', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);

    $first = $this->actingAs($this->approver)->get(PAYROLL_URL)->assertOk()->streamedContent();
    $document = DocumentRecord::query()->sole();

    expect($document->tipo)->toBe('nomina')
        ->and($document->referencia)->toBe('nomina')
        ->and($document->archivo)->toBe('Nomina_Comisiones_2026-08_v1.xlsx')
        ->and($document->ruta)->toBe('comisiones/informes/2026-08/v1/Nomina_Comisiones_2026-08_v1.xlsx')
        ->and(hash('sha256', $first))->toBe($document->sha256);

    $this->travel(1)->hours();
    $second = $this->actingAs($this->analyst)->get(PAYROLL_URL)->assertOk()->streamedContent();

    expect($second)->toBe($first)
        ->and(DocumentRecord::query()->count())->toBe(1)
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.generated')->count())->toBe(1)
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.downloaded')->count())->toBe(2)
        ->and(AuditRecord::query()->where('evento', 'comisiones.report.downloaded')->first()->contexto)
        ->toMatchArray(['version' => 1, 'referencia' => 'nomina', 'sha256' => $document->sha256]);

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-08/informes')->assertOk()
        ->assertSee('data-document="nomina"', false)
        ->assertSeeText('Nomina_Comisiones_2026-08_v1.xlsx');
});

it('refuses a stored payroll file that changed', function () {
    AugustCollections::close($this, $this->analyst, $this->approver);
    $this->actingAs($this->approver)->get(PAYROLL_URL)->assertOk();
    Storage::disk('local')->put(DocumentRecord::query()->sole()->ruta, 'otro');

    $this->actingAs($this->approver)->get(PAYROLL_URL)
        ->assertRedirect('/comisiones/periodos/2026-08/informes')
        ->assertSessionHas('error');
});
