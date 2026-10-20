<?php

use App\Modules\Comisiones\Infrastructure\Ingest\ImportCommissionBatch;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientAssignmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ClientRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CommissionLoad;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\PaymentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SaleRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SellerRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The SAP staging of the module: the BatchAccepted listener stages every
| Comisiones dataset of SGX, idempotently by natural key, and records the load.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 07:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
});

it('stages sales by type, entry and line, and gives the same rows when they arrive again', function () {
    $rows = [
        SapBatches::sale(),
        SapBatches::sale(['linea' => 1, 'total' => '250000.50']),
        SapBatches::sale(['tipo_objeto' => 14, 'doc_entry' => 600, 'numero_documento' => 100, 'clase_documento' => 'nota_credito', 'total' => '-50000.00']),
    ];

    $this->sap->send(Dataset::Sales, $rows);
    $this->sap->send(Dataset::Sales, [...$rows, SapBatches::sale(['linea' => 2, 'total' => '1.00'])], cutoff: '2026-08-01');

    expect(SaleRecord::query()->orderBy('clave')->pluck('total', 'clave')->all())->toBe([
        '13-5001-0' => '1000000.00',
        '13-5001-1' => '250000.50',
        '13-5001-2' => '1.00',
        '14-600-0' => '-50000.00',
    ]);

    $sale = SaleRecord::query()->where('clave', '13-5001-0')->sole();

    expect($sale->fecha_documento->format('Y-m-d'))->toBe('2026-07-15')
        ->and($sale->slp_code)->toBe(14)
        ->and($sale->moneda)->toBe('$')
        ->and($sale->trm)->toBeNull();

    $loads = CommissionLoad::query()->orderBy('id')->get();

    expect($loads->map(fn (CommissionLoad $load) => [$load->dataset, $load->filas, $load->insertadas, $load->actualizadas, $load->estado])->all())
        ->toBe([
            ['ventas', 3, 3, 0, 'aceptada'],
            ['ventas', 4, 1, 3, 'aceptada'],
        ])
        ->and($loads[1]->corte->format('Y-m-d'))->toBe('2026-08-01');
});

it('stages payments and SAP crossings, keeping the manual crossing fields apart', function () {
    $this->sap->send(Dataset::Collections, [SapBatches::payment(), SapBatches::payment(['linea' => 1, 'estado_pago' => 'anulado'])]);
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing(), SapBatches::crossing(['linea' => 1, 'tipo_cruce' => 'cliente-proveedor'])]);
    $this->sap->send(Dataset::Offsets, [SapBatches::crossing(['valor_compensado' => '595000.00'])]);

    expect(PaymentRecord::query()->orderBy('clave')->pluck('estado_pago', 'clave')->all())
        ->toBe(['7001-0' => 'vigente', '7001-1' => 'anulado']);

    $crossings = CrossingRecord::query()->orderBy('clave')->get();

    expect($crossings->pluck('clave')->all())->toBe(['sap-9001-0', 'sap-9001-1'])
        ->and($crossings[0]->valor_compensado)->toBe('595000.00')
        ->and($crossings[0]->origen)->toBe('sap')
        ->and($crossings[0]->estado->value)->toBe('aprobado')
        ->and($crossings[0]->estado_cruce)->toBe('vigente')
        ->and($crossings[1]->tipo_cruce)->toBe('cliente-proveedor');
});

it('keeps the clients, the sellers and the history of each client assignment', function () {
    $this->sap->send(Dataset::SalesRepMaster, [SapBatches::seller(), SapBatches::seller(['slp_code' => 15, 'nombre' => 'PAOLA VARGAS'])]);
    $this->sap->send(Dataset::CustomerMaster, [
        SapBatches::client(),
        SapBatches::client(['codigo_cliente' => 'C2', 'slp_code' => 15]),
    ], cutoff: '2026-07-01');

    // Same seller again: nothing new. C2 moves to 41 on 2026-07-16 by its modification date.
    $this->sap->send(Dataset::CustomerMaster, [
        SapBatches::client(),
        SapBatches::client(['codigo_cliente' => 'C2', 'slp_code' => 41, 'fecha_modificacion' => '2026-07-16']),
    ], cutoff: '2026-07-20');

    // C900123456 moves to 15 without a modification date: the cutoff is its start.
    $this->sap->send(Dataset::CustomerMaster, [
        SapBatches::client(['slp_code' => 15, 'razon_social' => 'Ferretería El Tornillo S.A.S.']),
    ], cutoff: '2026-08-01');

    $history = ClientAssignmentRecord::query()->orderBy('codigo_cliente')->orderBy('vigente_desde')->get()
        ->map(fn (ClientAssignmentRecord $row) => [$row->codigo_cliente, $row->slp_code, $row->vigente_desde->format('Y-m-d')])
        ->all();

    expect($history)->toBe([
        ['C2', 15, '2000-01-01'],
        ['C2', 41, '2026-07-16'],
        ['C900123456', 14, '2000-01-01'],
        ['C900123456', 15, '2026-08-01'],
    ])
        ->and(ClientRecord::query()->orderBy('codigo_cliente')->pluck('slp_code', 'codigo_cliente')->all())
        ->toBe(['C2' => 41, 'C900123456' => 15])
        ->and(ClientRecord::query()->where('codigo_cliente', 'C900123456')->value('razon_social'))->toBe('Ferretería El Tornillo S.A.S.')
        ->and(SellerRecord::query()->orderBy('slp_code')->pluck('nombre', 'slp_code')->all())
        ->toBe([14 => 'MONICA RIVERA AREVALO', 15 => 'PAOLA VARGAS']);
});

it('never moves an assignment before the start of the one in force', function () {
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()], cutoff: '2026-07-01');
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client(['slp_code' => 15, 'fecha_modificacion' => '2026-07-10'])], cutoff: '2026-07-10');
    // A stale modification date, older than the assignment in force: it corrects that assignment.
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client(['slp_code' => 41, 'fecha_modificacion' => '2026-07-05'])], cutoff: '2026-07-11');

    expect(ClientAssignmentRecord::query()->orderBy('vigente_desde')->get()
        ->map(fn (ClientAssignmentRecord $row) => [$row->slp_code, $row->vigente_desde->format('Y-m-d')])->all())
        ->toBe([[14, '2000-01-01'], [41, '2026-07-10']]);
});

it('ignores the batches of SDC and of the datasets of other modules', function () {
    $this->sap->send(Dataset::Sales, [SapBatches::sale()], company: CompanyCode::SDC);
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()], company: CompanyCode::SDC);
    $this->sap->send(Dataset::Advisors, [['slp_code' => 14, 'nombre' => 'Mónica', 'email' => null, 'activo' => 'Y', 'clientes' => 3]]);

    expect(SaleRecord::query()->count())->toBe(0)
        ->and(ClientAssignmentRecord::query()->count())->toBe(0)
        ->and(CommissionLoad::query()->count())->toBe(0);
});

it('queues nothing for an empty batch', function () {
    Queue::fake();

    $this->sap->send(Dataset::Sales, []);

    Queue::assertNothingPushed();

    $this->sap->send(Dataset::Collections, [SapBatches::payment()]);

    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ImportCommissionBatch::class);
});

it('skips the rows that lack their natural key', function () {
    $this->sap->send(Dataset::Sales, [SapBatches::sale(), ['saldo' => '10.00']]);

    expect(SaleRecord::query()->pluck('clave')->all())->toBe(['13-5001-0'])
        ->and(CommissionLoad::query()->sole()->filas)->toBe(1);
});
