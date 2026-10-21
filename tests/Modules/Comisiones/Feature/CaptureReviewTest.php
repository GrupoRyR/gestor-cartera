<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Domain\Calculation\SettlementResult;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ClosedPeriods;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The review of the manual captures (§7.4, §10): a user other than the
| creator approves or rejects (with a reason) a pending capture; its
| creator may delete it while pending. Only approved captures enter the
| calculation, which the last tests prove through the screens.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    Storage::fake('local');
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();
});

function reviewSupport(): UploadedFile
{
    return UploadedFile::fake()->create('soporte.pdf', 50, 'application/pdf');
}

/** Registers an OBR sale of code 14 in July through the screen and returns it. */
function registeredObr(object $test, ?object $user = null): ObrRecord
{
    $test->actingAs($user ?? $test->analyst)->post('/comisiones/ajustes/obr', [
        'periodo' => '2026-07',
        'codigo' => '14',
        'descripcion' => 'Escudos obsoletos',
        'valor_vendido' => '12.500.000',
        'tasa' => '2,5',
        'soporte' => reviewSupport(),
    ])->assertSessionHasNoErrors();

    return ObrRecord::query()->latest('id')->firstOrFail();
}

/** Calculates July through the screen and returns the result of the latest version. */
function calculatedJuly(object $test): SettlementResult
{
    $test->actingAs($test->analyst)->post('/comisiones/periodos/2026-07/calcular')->assertSessionHas('status');

    return app(EloquentSettlements::class)->latest(Period::of(2026, 7))->result;
}

it('serves the review steps under /comisiones/ajustes/{tipo}/{captura}', function () {
    expect(route('comisiones.ajustes.aprobar', ['tipo' => 'obr', 'captura' => 3], absolute: false))->toBe('/comisiones/ajustes/obr/3/aprobar')
        ->and(route('comisiones.ajustes.rechazar', ['tipo' => 'cruce', 'captura' => 3], absolute: false))->toBe('/comisiones/ajustes/cruce/3/rechazar')
        ->and(route('comisiones.ajustes.destroy', ['tipo' => 'reasignacion', 'captura' => 3], absolute: false))->toBe('/comisiones/ajustes/reasignacion/3');

    $this->post('/comisiones/ajustes/obr/1/aprobar')->assertRedirect('/login');
});

it('lets only approvers review and only capturers delete', function () {
    $obr = registeredObr($this);
    $viewer = ComisionesUsers::viewer();

    $this->actingAs($viewer)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")->assertForbidden();
    $this->actingAs($viewer)->delete("/comisiones/ajustes/obr/{$obr->id}")->assertForbidden();
    $this->actingAs($this->analyst)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")->assertForbidden();
    $this->actingAs($this->analyst)->post("/comisiones/ajustes/obr/{$obr->id}/rechazar", ['motivo_rechazo' => 'No'])->assertForbidden();
    $this->actingAs($this->approver)->delete("/comisiones/ajustes/obr/{$obr->id}")->assertForbidden();

    foreach (ComisionesUsers::notViewers() as [$roles]) {
        $user = ComisionesUsers::create($roles, fake()->unique()->safeEmail());
        $this->actingAs($user)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")->assertForbidden();
        $this->actingAs($user)->delete("/comisiones/ajustes/obr/{$obr->id}")->assertForbidden();
    }

    expect($obr->fresh()->estado)->toBe(CaptureStatus::Pending);
});

it('approves a pending capture by another user, records the reviewer and audits it', function () {
    $obr = registeredObr($this);

    $this->actingAs($this->approver)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSee('action="'.route('comisiones.ajustes.aprobar', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false)
        ->assertSee('action="'.route('comisiones.ajustes.rechazar', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false);

    $this->travelTo(CarbonImmutable::parse('2026-08-04 15:20:00', 'America/Bogota'));
    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")
        ->assertRedirect('/comisiones/ajustes/obr?periodo=2026-07')
        ->assertSessionHas('status', 'Aprobaste la venta OBR. Ya entra en la liquidación de 2026-07.');

    $obr->refresh();
    expect($obr->estado)->toBe(CaptureStatus::Approved)
        ->and($obr->revisado_por)->toBe($this->approver->id)
        ->and($obr->revisado_en?->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-08-04 15:20');

    $approved = AuditRecord::query()->where('evento', 'comisiones.capture.approved')->sole();
    expect($approved->sujeto_tipo)->toBe('obr')
        ->and($approved->user_id)->toBe($this->approver->id)
        ->and($approved->periodo)->toBe('2026-07')
        // MySQL's JSON columns reorder keys: compare the pair, not its order.
        ->and($approved->cambios['estado'])->toEqual(['old' => 'pendiente', 'new' => 'aprobado']);

    $this->actingAs($this->analyst)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSee('data-estado="aprobado"', false)
        ->assertSeeText('Aprobado')
        ->assertSeeText('04/08/2026 15:20 por Beto Ruiz')
        ->assertDontSee('action="'.route('comisiones.ajustes.destroy', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false);

    // Reviewed once: neither approved, rejected nor deleted again.
    $message = 'Ese registro ya no está pendiente: solo se aprueba, rechaza o elimina mientras está pendiente.';
    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")->assertSessionHas('error', $message);
    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/rechazar", ['motivo_rechazo' => 'Me equivoqué'])->assertSessionHas('error', $message);
    $this->actingAs($this->analyst)->delete("/comisiones/ajustes/obr/{$obr->id}")->assertSessionHas('error', $message);
    expect(AuditRecord::query()->where('evento', 'comisiones.capture.approved')->count())->toBe(1);
});

it('refuses the approval of the user who registered the capture', function () {
    $both = ComisionesUsers::create([Role::PortfolioManager->value, ModuleRoles::APPROVER], 'doble.rol@segurex.com', 'Diana Doble');
    $obr = registeredObr($this, $both);

    $this->actingAs($both)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSeeText('Lo registraste tú: otra persona debe revisarlo.')
        ->assertDontSee('action="'.route('comisiones.ajustes.aprobar', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false);

    $this->actingAs($both)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")
        ->assertSessionHas('error', 'Tú registraste este ajuste: otra persona debe aprobarlo o rechazarlo.');
    $this->actingAs($both)->post("/comisiones/ajustes/obr/{$obr->id}/rechazar", ['motivo_rechazo' => 'Quiero rechazarlo yo'])
        ->assertSessionHas('error', 'Tú registraste este ajuste: otra persona debe aprobarlo o rechazarlo.');

    expect($obr->fresh()->estado)->toBe(CaptureStatus::Pending);
});

it('rejects a pending capture only with a reason, shown in the list', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', [
        'periodo' => '2026-07', 'codigo' => '14', 'tipo' => 'reintegro', 'valor' => '350.000',
        'descripcion' => 'Comisión de junio', 'soporte' => reviewSupport(),
    ]);
    $adjustment = AdjustmentRecord::query()->sole();

    $this->actingAs($this->approver)->post("/comisiones/ajustes/ajuste/{$adjustment->id}/rechazar", ['captura' => $adjustment->id, 'motivo_rechazo' => ''])
        ->assertSessionHasErrors('motivo_rechazo');
    expect($adjustment->fresh()->estado)->toBe(CaptureStatus::Pending);

    $this->actingAs($this->approver)->post("/comisiones/ajustes/ajuste/{$adjustment->id}/rechazar", ['captura' => $adjustment->id, 'motivo_rechazo' => 'El acta no tiene firma'])
        ->assertRedirect('/comisiones/ajustes/reintegros?periodo=2026-07')
        ->assertSessionHas('status', 'Rechazaste el ajuste.');

    $adjustment->refresh();
    expect($adjustment->estado)->toBe(CaptureStatus::Rejected)
        ->and($adjustment->motivo_rechazo)->toBe('El acta no tiene firma')
        ->and($adjustment->revisado_por)->toBe($this->approver->id);
    $this->fakes->audit->assertRecorded('comisiones.capture.rejected', fn (AuditEntry $entry) => $entry->subjectType === 'ajuste' && $entry->context['motivo'] === 'El acta no tiene firma');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes/reintegros?periodo=2026-07')->assertOk()
        ->assertSee('data-estado="rechazado"', false)
        ->assertSeeText('Rechazado')
        ->assertSeeText('Motivo: El acta no tiene firma');
});

it('lets the creator delete a pending capture with its support, and nobody else', function () {
    $obr = registeredObr($this);
    $other = ComisionesUsers::create([Role::PortfolioManager->value], 'otro.gerente@segurex.com', 'Óscar Peña');

    $this->actingAs($other)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertDontSee('action="'.route('comisiones.ajustes.destroy', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false);
    $this->actingAs($other)->delete("/comisiones/ajustes/obr/{$obr->id}")
        ->assertSessionHas('error', 'Solo quien registró el ajuste puede eliminarlo.');

    $this->actingAs($this->analyst)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertSee('action="'.route('comisiones.ajustes.destroy', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false);
    $this->actingAs($this->analyst)->delete("/comisiones/ajustes/obr/{$obr->id}")
        ->assertRedirect('/comisiones/ajustes/obr?periodo=2026-07')
        ->assertSessionHas('status', 'Eliminaste la venta OBR.');

    expect(ObrRecord::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($obr->soporte);
    $deleted = AuditRecord::query()->where('evento', 'comisiones.capture.deleted')->sole();
    expect($deleted->sujeto_id)->toBe((string) $obr->id)
        ->and($deleted->cambios['descripcion'])->toBe('Escudos obsoletos');
});

it('answers 404 for an unknown capture or a SAP crossing', function () {
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing()]);
    $sap = CrossingRecord::query()->sole();

    $this->actingAs($this->approver)->post('/comisiones/ajustes/obr/999/aprobar')->assertNotFound();
    $this->actingAs($this->approver)->post("/comisiones/ajustes/cruce/{$sap->id}/aprobar")->assertNotFound();
    $this->actingAs($this->analyst)->delete("/comisiones/ajustes/cruce/{$sap->id}")->assertNotFound();
});

it('refuses to review or delete captures of a closed period', function () {
    $obr = registeredObr($this);
    ClosedPeriods::close($this, $this->sap, $this->analyst, $this->approver);
    $message = 'El período está cerrado. Para corregirlo registra una liquidación complementaria.';

    $this->actingAs($this->approver)->get('/comisiones/ajustes?periodo=2026-07')->assertOk()
        ->assertDontSee('action="'.route('comisiones.ajustes.aprobar', ['tipo' => 'obr', 'captura' => $obr->id]).'"', false);
    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar")->assertSessionHas('error', $message);
    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/rechazar", ['motivo_rechazo' => 'Tarde'])->assertSessionHas('error', $message);
    $this->actingAs($this->analyst)->delete("/comisiones/ajustes/obr/{$obr->id}")->assertSessionHas('error', $message);

    expect($obr->fresh()->estado)->toBe(CaptureStatus::Pending);
});

it('counts an OBR sale and a reimbursement in the settlement only once approved', function () {
    $this->sap->send(Dataset::Sales, [SapBatches::sale(['total' => '69915134.00'])]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);
    $obr = registeredObr($this);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', [
        'periodo' => '2026-07', 'codigo' => '14', 'tipo' => 'reintegro', 'valor' => '350.000',
        'descripcion' => 'Comisión de junio', 'soporte' => reviewSupport(),
    ]);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reintegros', [
        'periodo' => '2026-07', 'codigo' => '14', 'tipo' => 'descuento', 'valor' => '100.000',
        'descripcion' => 'Anticipo', 'soporte' => reviewSupport(),
    ]);
    [$reimbursement, $deduction] = AdjustmentRecord::query()->orderBy('id')->get()->all();

    $pending = calculatedJuly($this)->code(14);
    expect($pending->obrCommission->amount())->toBe('0')
        ->and($pending->reimbursements->amount())->toBe('0')
        ->and($pending->deductions->amount())->toBe('0');

    $this->actingAs($this->approver)->post("/comisiones/ajustes/obr/{$obr->id}/aprobar");
    $this->actingAs($this->approver)->post("/comisiones/ajustes/ajuste/{$reimbursement->id}/aprobar");
    $this->actingAs($this->approver)->post("/comisiones/ajustes/ajuste/{$deduction->id}/rechazar", ['motivo_rechazo' => 'Sin soporte válido']);

    $approved = calculatedJuly($this)->code(14);
    expect($approved->obrCommission->amount())->toBe('312500')
        ->and($approved->reimbursements->amount())->toBe('350000')
        ->and($approved->deductions->amount())->toBe('0')
        ->and($approved->total->minus($pending->total)->amount())->toBe('662500');
});

it('counts a manual crossing for its seller only once approved', function () {
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/cruces', [
        'vendedor' => '15', 'tipo_cruce' => 'cliente-proveedor', 'factura' => 'FV-8123',
        'fecha_factura' => '2026-06-10', 'fecha_cruce' => '2026-07-25',
        'codigo_cliente' => 'C800111222', 'razon_social' => 'Distribuciones La 80 SAS', 'tercero' => 'P800111222',
        'importe_original' => '2.713.450', 'valor_cruzado' => '2.713.450', 'iva_factura' => '433.240',
        'soporte' => reviewSupport(),
    ])->assertSessionHasNoErrors();
    $crossing = CrossingRecord::query()->sole();

    expect(calculatedJuly($this)->code(15)?->collected->amount() ?? '0')->toBe('0');

    $this->actingAs($this->approver)->post("/comisiones/ajustes/cruce/{$crossing->id}/aprobar")->assertSessionHas('status');

    // 2,713,450 × (2,713,450 − 433,240) / 2,713,450: the base without VAT (§5.4).
    expect(calculatedJuly($this)->code(15)->collected->amount())->toBe('2280210');
});

it('moves a crossing to the seller of an approved reassignment, not of a pending one', function () {
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()]);
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing()]);
    $this->actingAs($this->analyst)->post('/comisiones/ajustes/reasignaciones', [
        'periodo' => '2026-07', 'clave_cruce' => 'sap-9001-0', 'codigo' => '15',
        'motivo' => 'El cliente pasó a la zona de Bogotá', 'soporte' => reviewSupport(),
    ])->assertSessionHasNoErrors();
    $reassignment = ReassignmentRecord::query()->sole();

    $before = calculatedJuly($this);
    expect($before->code(14)->collected->amount())->toBe('1000000')
        ->and($before->code(15)?->collected->amount() ?? '0')->toBe('0');

    $this->actingAs($this->approver)->post("/comisiones/ajustes/reasignacion/{$reassignment->id}/aprobar")->assertSessionHas('status');

    $after = calculatedJuly($this);
    expect($after->code(14)?->collected->amount() ?? '0')->toBe('0')
        ->and($after->code(15)->collected->amount())->toBe('1000000')
        ->and($after->code(15)->collectionCommission->amount())->toBe($before->code(14)->collectionCommission->amount());
});
