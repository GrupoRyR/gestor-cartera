<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterVersions;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| A settlement is approved and closed only on the inputs it was calculated
| with: a budget change, an approved capture or a parameter version of the
| period recorded after the calculation asks for a recalculation first, so
| an approved reimbursement is never left out of a closed period unseen.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();

    // Code 14 of July 2026 (Annex 2): 90.5 % of its budget, no blocking alert.
    (new SapBatches($this->fakes->ingesta))->send(Dataset::Sales, [
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['linea' => 1, 'total' => '9915134.00']),
    ]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
});

function staleMessage(int $revision, string $step): string
{
    return "Hay cambios en el presupuesto, en capturas aprobadas o en parámetros de 2026-07 después del cálculo de la revisión {$revision}. Recalcula la liquidación antes de {$step}.";
}

it('asks to recalculate before approving when a capture was approved after the calculation', function () {
    $obr = ObrRecord::query()->create([
        'periodo' => '2026-07', 'slp_code' => 14, 'descripcion' => 'Escudos', 'valor_vendido' => '1000000.00', 'tasa' => '2',
        'soporte' => 'comisiones/soportes/obr/x.pdf', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id,
    ]);

    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")->assertSessionHas('status');

    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()->assertSeeText(staleMessage(1, 'aprobarla'));
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('error', staleMessage(1, 'aprobarla'));

    expect(SettlementRecord::query()->sole()->estado)->toBe('borrador');

    // Recalculated, the capture is in and the approval goes through.
    $this->travel(1)->minutes();
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 2])->assertSessionHas('status');

    expect(SettlementRecord::query()->sole()->estado)->toBe('aprobada');
});

it('asks to recalculate before closing when the budget changed after the calculation', function () {
    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])->assertSessionHas('status');

    $this->travel(1)->minutes();
    $this->actingAs($this->analyst)->put('/comisiones/presupuesto/2026-07/14', ['filas' => [14 => ['valor' => '70.000.000']]])->assertSessionHas('status');

    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/cerrar')
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('error', staleMessage(1, 'cerrarla'));

    expect(SettlementRecord::query()->sole()->estado)->toBe('aprobada');
});

it('asks to recalculate when a parameter version of the period was added after the calculation', function () {
    $this->travel(1)->minutes();
    $admin = ComisionesUsers::create(['admin'], 'ti@segurex.com', 'TI');
    $code14 = ParameterRecord::query()->where('tipo', 'codigo')->where('clave', '14')->sole();
    app(ParameterVersions::class)->add('codigo', '14', '2026-07-01', [...$code14->datos, 'tasa_venta' => '1'], 'Tasa nueva', $admin);

    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertSessionHas('error', staleMessage(1, 'aprobarla'));
});

it('ignores a parameter version that starts after the period', function () {
    $this->travel(1)->minutes();
    $admin = ComisionesUsers::create(['admin'], 'ti@segurex.com', 'TI');
    $code14 = ParameterRecord::query()->where('tipo', 'codigo')->where('clave', '14')->sole();
    app(ParameterVersions::class)->add('codigo', '14', '2026-09-01', [...$code14->datos, 'tasa_venta' => '1'], 'Tasa de septiembre', $admin);

    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])->assertSessionHas('status');
});

it('lets changes made before the calculation through', function () {
    $this->travel(1)->minutes();
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])->assertSessionHas('status');
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/cerrar')->assertSessionHas('status');

    expect(SettlementRecord::query()->sole()->estado)->toBe('cerrada');
});
