<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ClosedPeriods;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The monthly budget (§7.3, Parte B «Dato que no viene de SAP»): one row
| per code in force, edited on screen or loaded with the template
| «Período; Código de vendedor; Presupuesto; Canal», all or nothing.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
});

/** A CSV upload with these lines, UTF-8. */
function budgetCsv(string $content, string $name = 'presupuesto.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content);
}

/**
 * An .xlsx upload with these rows.
 *
 * @param  list<list<mixed>>  $rows
 */
function budgetWorkbook(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'presupuesto').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    foreach ($rows as $cells) {
        $writer->addRow(Row::fromValues($cells));
    }

    $writer->close();

    return new UploadedFile($path, 'presupuesto.xlsx', null, null, true);
}

it('serves the budget under /comisiones/presupuesto', function () {
    expect(route('comisiones.presupuesto.index', absolute: false))->toBe('/comisiones/presupuesto')
        ->and(route('comisiones.presupuesto.update', ['periodo' => '2026-07', 'codigo' => 14], absolute: false))->toBe('/comisiones/presupuesto/2026-07/14')
        ->and(route('comisiones.presupuesto.destroy', ['periodo' => '2026-07', 'codigo' => 14], absolute: false))->toBe('/comisiones/presupuesto/2026-07/14')
        ->and(route('comisiones.presupuesto.import', '2026-07', absolute: false))->toBe('/comisiones/presupuesto/2026-07/carga')
        ->and(route('comisiones.presupuesto.template', '2026-07', absolute: false))->toBe('/comisiones/presupuesto/2026-07/plantilla');
});

it('sends guests to the login', function () {
    $this->get('/comisiones/presupuesto')->assertRedirect('/login');
    $this->put('/comisiones/presupuesto/2026-07/14')->assertRedirect('/login');
    $this->post('/comisiones/presupuesto/2026-07/carga')->assertRedirect('/login');
});

it('forbids the budget to roles without comisiones.view', function (array $roles) {
    $user = ComisionesUsers::create($roles);

    $this->actingAs($user)->get('/comisiones/presupuesto')->assertForbidden();
    $this->actingAs($user)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '1']]])->assertForbidden();
})->with(ComisionesUsers::notViewers());

it('shows the budget read only to the viewer and the approver, who cannot change it', function () {
    foreach ([ComisionesUsers::viewer(), ComisionesUsers::approver()] as $user) {
        $this->actingAs($user)->get('/comisiones/presupuesto?periodo=2026-07')->assertOk()
            ->assertSeeText('Presupuesto por código')
            ->assertDontSee('action="'.route('comisiones.presupuesto.import', '2026-07').'"', false)
            ->assertDontSee('name="filas[14][valor]"', false);

        $this->actingAs($user)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '1']]])->assertForbidden();
        $this->actingAs($user)->delete('/comisiones/presupuesto/2026-07/14')->assertForbidden();
        $this->actingAs($user)->post('/comisiones/presupuesto/2026-07/carga')->assertForbidden();
        $this->actingAs($user)->get('/comisiones/presupuesto/2026-07/plantilla')->assertForbidden();
    }

    expect(BudgetRecord::query()->count())->toBe(0);
});

it('lists the regular and separate codes in force, not the control ones, on the month in course by default', function () {
    $response = $this->actingAs($this->analyst)->get('/comisiones/presupuesto')->assertOk()
        ->assertSeeText('Presupuesto')
        ->assertSee('data-code="14"', false)
        ->assertSee('data-code="84"', false)
        ->assertDontSee('data-code="80"', false)
        ->assertDontSee('data-code="100"', false)
        ->assertSee('action="'.route('comisiones.presupuesto.import', '2026-08').'"', false);

    expect(substr_count($response->getContent(), 'data-code="'))->toBeGreaterThan(10);
});

it('calls out the codes with a minimum and no budget', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);

    $this->actingAs($this->analyst)->get('/comisiones/presupuesto?periodo=2026-07')->assertOk()
        ->assertSeeText('Códigos sin presupuesto')
        ->assertSee('data-missing="15"', false)
        ->assertSee('data-missing="16"', false)
        ->assertDontSee('data-missing="14"', false)
        // No minimum (50, 53, 85 and the directors): no alert, so not listed.
        ->assertDontSee('data-missing="50"', false)
        ->assertDontSee('data-missing="84"', false);
});

it('edits the budget of a code on screen, creating and then updating it, and audits both', function () {
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '$ 77.241.857', 'canal' => 'Distribución']]])
        ->assertRedirect('/comisiones/presupuesto?periodo=2026-07')
        ->assertSessionHas('status', 'Guardaste el presupuesto del código 14 de 2026-07.');

    $budget = BudgetRecord::query()->sole();

    expect($budget->valor)->toBe('77241857.00')
        ->and($budget->canal)->toBe('Distribución')
        ->and($budget->created_by)->toBe($this->analyst->id)
        ->and($budget->updated_by)->toBe($this->analyst->id);

    $other = ComisionesUsers::create([Role::PortfolioManager->value], 'otro.gerente@segurex.com', 'Óscar Peña');
    $this->travelTo(CarbonImmutable::parse('2026-08-04 10:30:00', 'America/Bogota'));
    $this->actingAs($other)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '80000000,50', 'canal' => '']]])
        ->assertSessionHas('status');

    expect($budget->fresh()->valor)->toBe('80000000.50')
        ->and($budget->fresh()->canal)->toBeNull()
        ->and($budget->fresh()->updated_by)->toBe($other->id);

    $updates = AuditRecord::query()->where('evento', 'comisiones.budget.updated')->orderBy('id')->get();

    // MySQL's JSON columns reorder keys: the audit changes are compared as pairs, not in order.
    expect($updates)->toHaveCount(2)
        ->and($updates[0]->cambios['valor'])->toEqual(['old' => null, 'new' => '77241857.00'])
        ->and($updates[1]->cambios['valor'])->toEqual(['old' => '77241857.00', 'new' => '80000000.50'])
        ->and($updates[1]->cambios['canal'])->toEqual(['old' => 'Distribución', 'new' => null])
        ->and($updates[1]->periodo)->toBe('2026-07');
    $this->fakes->audit->assertRecorded('comisiones.budget.updated', fn (AuditEntry $entry) => $entry->context['codigo'] === 14);

    $this->actingAs($other)->get('/comisiones/presupuesto?periodo=2026-07')->assertOk()
        ->assertSee('value="80.000.000,50"', false)
        ->assertSeeText('04/08/2026 10:30 por Óscar Peña');
});

it('refuses a budget that is not an amount, or not above zero, under its row', function () {
    $this->actingAs($this->analyst)->from('/comisiones/presupuesto?periodo=2026-07')
        ->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => 'mucho']]])
        ->assertSessionHasErrors(['filas.14.valor' => 'Escribe el presupuesto como 77241857 o 77.241.857.']);
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '0']]])
        ->assertSessionHasErrors(['filas.14.valor' => 'El presupuesto debe ser mayor que cero.']);
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '']]])
        ->assertSessionHasErrors('filas.14.valor');

    expect(BudgetRecord::query()->count())->toBe(0);
});

it('refuses the budget of a code without parameters in the period', function () {
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/99', ['filas' => [99 => ['valor' => '1000']]])
        ->assertSessionHas('error', 'El código 99 no tiene parámetros vigentes en 2026-07.');
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/80', ['filas' => [80 => ['valor' => '1000']]])
        ->assertSessionHas('error', 'El código 80 no tiene parámetros vigentes en 2026-07.');

    expect(BudgetRecord::query()->count())->toBe(0);
});

it('deletes the budget of a code and audits the old value', function () {
    $budget = BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'canal' => 'Distribución', 'created_by' => $this->analyst->id]);

    $this->actingAs($this->analyst)->delete('/comisiones/presupuesto/2026-07/14')
        ->assertRedirect('/comisiones/presupuesto?periodo=2026-07')
        ->assertSessionHas('status', 'Eliminaste el presupuesto del código 14 de 2026-07.');

    expect(BudgetRecord::query()->count())->toBe(0);
    $deleted = AuditRecord::query()->where('evento', 'comisiones.budget.deleted')->sole();
    expect($deleted->sujeto_id)->toBe((string) $budget->id)
        ->and($deleted->cambios['valor'])->toEqual(['old' => '77241857.00', 'new' => null]);
});

it('loads a CSV template with Colombian amounts, both periods formats and both delimiters', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 15, 'valor' => '1000.00', 'created_by' => $this->analyst->id]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 16, 'valor' => '5000000.00', 'canal' => 'Retail', 'created_by' => $this->analyst->id]);

    $csv = "\u{FEFF}Período;Código de vendedor;Presupuesto;Canal\r\n"
        ."2026-07;14;77.241.857;Distribución\r\n"
        ."07/2026;15;$ 1.500.000,50;\r\n"
        .";;;\r\n"
        ."2026-07;16;5000000;Retail\r\n";

    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', ['archivo' => budgetCsv($csv)])
        ->assertRedirect('/comisiones/presupuesto?periodo=2026-07')
        ->assertSessionHas('status', 'Cargaste el presupuesto de 2026-07: 1 nuevos, 1 actualizados y 1 sin cambios.');

    expect(BudgetRecord::query()->orderBy('slp_code')->get()->map(fn ($row) => [$row->slp_code, $row->valor, $row->canal])->all())->toBe([
        [14, '77241857.00', 'Distribución'],
        [15, '1500000.50', null],
        [16, '5000000.00', 'Retail'],
    ]);

    $imported = AuditRecord::query()->where('evento', 'comisiones.budget.imported')->sole();
    expect($imported->periodo)->toBe('2026-07')
        ->and($imported->contexto['archivo'])->toBe('presupuesto.csv')
        ->and($imported->cambios['codigo_14'])->toEqual(['old' => null, 'new' => ['valor' => '77241857.00', 'canal' => 'Distribución']])
        ->and($imported->cambios['codigo_15']['old'])->toEqual(['valor' => '1000.00', 'canal' => null])
        ->and($imported->cambios)->not->toHaveKey('codigo_16');

    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', [
        'archivo' => budgetCsv("Periodo,Codigo de vendedor,Presupuesto\n2026-07,14,\"77241857.25\"\n"),
    ])->assertSessionHas('status');

    expect(BudgetRecord::query()->where('slp_code', 14)->value('valor'))->toBe('77241857.25');
});

it('loads an Excel template with numeric cells', function () {
    $file = budgetWorkbook([
        ['Período', 'Código de vendedor', 'Presupuesto', 'Canal'],
        ['2026-07', 14, 77241857, 'Distribución'],
        ['2026-07', '21', '12.500.000', null],
    ]);

    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', ['archivo' => $file])
        ->assertSessionHas('status', 'Cargaste el presupuesto de 2026-07: 2 nuevos, 0 actualizados y 0 sin cambios.');

    expect(BudgetRecord::query()->orderBy('slp_code')->pluck('valor', 'slp_code')->all())->toBe([14 => '77241857.00', 21 => '12500000.00']);
});

it('rejects the whole file when a row is wrong, with a message per wrong row', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '1000.00', 'created_by' => $this->analyst->id]);

    $csv = "Período;Código de vendedor;Presupuesto;Canal\n"
        ."2026-07;14;2.000;Distribución\n"
        ."2026-08;15;1000;\n"
        ."2026-07;99;1000;\n"
        ."2026-07;80;1000;\n"
        ."2026-07;16;mucho;\n"
        ."2026-07;14;3000;\n"
        ."13/2026;abc;-5;\n";

    $this->actingAs($this->analyst)->from('/comisiones/presupuesto?periodo=2026-07')
        ->post('/comisiones/presupuesto/2026-07/carga', ['archivo' => budgetCsv($csv)])
        ->assertRedirect('/comisiones/presupuesto?periodo=2026-07')
        ->assertSessionHasErrors(['archivo' => 'No guardamos nada: corrige las filas con error y vuelve a subir el archivo.'])
        ->assertSessionHas('errores_carga', [
            'Fila 3: el período 2026-08 no es el que estás cargando (2026-07).',
            'Fila 4: el código 99 no tiene parámetros vigentes en 2026-07.',
            'Fila 5: el código 80 no tiene parámetros vigentes en 2026-07.',
            'Fila 6: el presupuesto «mucho» no es un valor válido.',
            'Fila 7: el código 14 ya está en la fila 2.',
            'Fila 8: el período «13/2026» no es válido; escríbelo como AAAA-MM o MM/AAAA. el código de vendedor «abc» no es válido. el presupuesto debe ser mayor que cero.',
        ]);

    expect(BudgetRecord::query()->sole()->valor)->toBe('1000.00')
        ->and(AuditRecord::query()->where('evento', 'comisiones.budget.imported')->exists())->toBeFalse();

    $this->actingAs($this->analyst)->get('/comisiones/presupuesto?periodo=2026-07')->assertOk()
        ->assertSeeText('Fila 4: el código 99 no tiene parámetros vigentes en 2026-07.');
});

it('rejects a file without the headers, without rows, or of another format', function () {
    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', ['archivo' => budgetCsv("2026-07;14;1000\n")])
        ->assertSessionHas('errores_carga', ['La primera fila debe tener los encabezados Período; Código de vendedor; Presupuesto; Canal.']);
    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', ['archivo' => budgetCsv("Período;Código de vendedor;Presupuesto;Canal\n")])
        ->assertSessionHas('errores_carga', ['El archivo solo tiene encabezados: agrega una fila por código.']);
    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', ['archivo' => UploadedFile::fake()->create('presupuesto.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('archivo');
    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', [])
        ->assertSessionHasErrors('archivo');

    expect(BudgetRecord::query()->count())->toBe(0);
});

it('downloads the template prefilled with the period, the codes and what is loaded', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'canal' => 'Distribución', 'created_by' => $this->analyst->id]);
    $this->sap->send(Dataset::SalesRepMaster, [SapBatches::seller(['slp_code' => 15, 'nombre' => 'CODIGO 15', 'canal' => 'Retail'])]);

    $response = $this->actingAs($this->analyst)->get('/comisiones/presupuesto/2026-07/plantilla')->assertOk();

    expect($response->headers->get('content-type'))->toContain('text/csv')
        ->and($response->headers->get('content-disposition'))->toContain('Plantilla_Presupuesto_2026-07.csv');

    $content = $response->streamedContent();
    $lines = preg_split('/\r\n/', trim(substr($content, 3)));

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($lines[0])->toBe('Período;Código de vendedor;Presupuesto;Canal')
        ->and($lines)->toContain('2026-07;14;77.241.857;Distribución')
        ->and($lines)->toContain('2026-07;15;;Retail')
        ->and(collect($lines)->first(fn ($line) => str_starts_with($line, '2026-07;80;')))->toBeNull();

    // The template goes back in as it came out.
    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', [
        'archivo' => budgetCsv("Período;Código de vendedor;Presupuesto;Canal\r\n2026-07;14;77.241.857;Distribución\r\n"),
    ])->assertSessionHas('status', 'Cargaste el presupuesto de 2026-07: 0 nuevos, 0 actualizados y 1 sin cambios.');
});

it('refuses every budget change in a closed period, and allows it again after a complementary settlement', function () {
    $approver = ComisionesUsers::approver();
    ClosedPeriods::close($this, $this->sap, $this->analyst, $approver);

    $this->actingAs($this->analyst)->get('/comisiones/presupuesto?periodo=2026-07')->assertOk()
        ->assertSeeText('El período está cerrado. Para corregirlo registra una liquidación complementaria.')
        ->assertDontSee('name="filas[14][valor]"', false)
        ->assertDontSee('action="'.route('comisiones.presupuesto.import', '2026-07').'"', false);

    $message = 'El período está cerrado. Para corregirlo registra una liquidación complementaria.';
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '1000']]])->assertSessionHas('error', $message);
    $this->actingAs($this->analyst)->delete('/comisiones/presupuesto/2026-07/14')->assertSessionHas('error', $message);
    $this->actingAs($this->analyst)->post('/comisiones/presupuesto/2026-07/carga', [
        'archivo' => budgetCsv("Período;Código de vendedor;Presupuesto\n2026-07;14;1000\n"),
    ])->assertSessionHas('error', $message);

    expect(BudgetRecord::query()->count())->toBe(0);

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/complementaria', ['motivo' => 'Faltaba el presupuesto']);

    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '1000']]])->assertSessionHas('status');
    expect(BudgetRecord::query()->count())->toBe(1);
});
