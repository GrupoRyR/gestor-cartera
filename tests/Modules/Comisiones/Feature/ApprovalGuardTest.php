<?php

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterVersions;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalGuard;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalRefused;
use App\Modules\Core\Contracts\Enums\Dataset;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| Segregation of duties beyond the engine (§10): whoever loads captures of
| the period or changes its parameters never approves its settlement, and a
| capture is reviewed by someone other than its creator.
*/

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->guard = app(ApprovalGuard::class);
    $this->july = Period::of(2026, 7);
    $this->approver = User::factory()->create();
    $this->analyst = User::factory()->create();
});

it('lets an approver who loaded nothing of the period approve it', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '1.00', 'created_by' => $this->analyst->id]);
    BudgetRecord::query()->create(['periodo' => '2026-08', 'slp_code' => 14, 'valor' => '1.00', 'created_by' => $this->approver->id]);

    expect($this->guard->reasons($this->july, $this->approver))->toBe([]);

    $this->guard->assertMayApprove($this->july, $this->approver);
});

it('lists every capture of the period the approver loaded, whatever its state', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '1.00', 'created_by' => $this->analyst->id, 'updated_by' => $this->approver->id]);
    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 33, 'descripcion' => 'Escudos', 'valor_vendido' => '1.00', 'tasa' => '1', 'estado' => CaptureStatus::Rejected, 'created_by' => $this->approver->id]);
    AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'reintegro', 'valor' => '1.00', 'descripcion' => 'x', 'estado' => CaptureStatus::Approved, 'created_by' => $this->approver->id]);
    CrossingRecord::query()->create([
        'clave' => 'manual-1', 'origen' => 'manual', 'fecha_cruce' => '2026-07-18', 'tipo_cruce' => 'anticipo', 'estado_cruce' => 'vigente',
        'codigo_cliente' => 'C1', 'valor_compensado' => '1.00', 'estado' => CaptureStatus::Pending, 'created_by' => $this->approver->id,
    ]);
    (new SapBatches($this->fakes->ingesta))->send(Dataset::Offsets, [SapBatches::crossing()]);
    ReassignmentRecord::query()->create(['clave_cruce' => 'sap-9001-0', 'slp_code' => 15, 'motivo' => 'x', 'estado' => CaptureStatus::Pending, 'created_by' => $this->approver->id]);

    expect($this->guard->reasons($this->july, $this->approver))->toBe([
        'Cargaste o editaste el presupuesto de 2026-07.',
        'Registraste ventas OBR de 2026-07.',
        'Registraste reintegros o descuentos de 2026-07.',
        'Registraste cruces manuales de 2026-07.',
        'Registraste reasignaciones de cruces de 2026-07.',
    ]);

    expect(fn () => $this->guard->assertMayApprove($this->july, $this->approver))->toThrow(function (ApprovalRefused $refused) {
        expect($refused->getMessage())->toBe('No puedes aprobar la liquidación de 2026-07: quien carga datos o cambia parámetros del período no la aprueba.')
            ->and($refused->reasons())->toHaveCount(5);
    });

    expect($this->guard->reasons(Period::of(2026, 8), $this->approver))->toBe([]);
});

it('refuses an approver who added a parameter version in force in the period', function () {
    $data = [
        'nombre' => 'Mónica Rivera',
        'persona' => ['id' => 'monica-rivera', 'nombre' => 'Mónica Rivera'],
        'tasa_venta' => '1',
        'tasa_recaudo' => '0.75',
        'cumplimiento_minimo' => '90',
        'alcance' => 'regular',
        'usa_tramos' => true,
    ];
    app(ParameterVersions::class)->add('codigo', '14', '2026-07-16', $data, 'Desde mediados de julio', $this->approver);

    expect($this->guard->reasons($this->july, $this->approver))->toBe(['Agregaste versiones de parámetros vigentes en 2026-07.'])
        ->and($this->guard->reasons(Period::of(2026, 6), $this->approver))->toBe([])
        ->and($this->guard->reasons(Period::of(2026, 9), $this->approver))->toBe(['Agregaste versiones de parámetros vigentes en 2026-09.']);
});

it('lets only someone other than its creator review a capture', function () {
    $adjustment = AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'reintegro', 'valor' => '1.00', 'descripcion' => 'x', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id]);

    expect($this->guard->mayReview($adjustment, $this->analyst))->toBeFalse()
        ->and($this->guard->mayReview($adjustment, $this->approver))->toBeTrue();
});
