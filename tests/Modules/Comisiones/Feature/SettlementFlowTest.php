<?php

use App\Models\User;
use App\Modules\Comisiones\Application\ApproveSettlement;
use App\Modules\Comisiones\Application\CalculateSettlement;
use App\Modules\Comisiones\Application\CloseSettlement;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| End to end on the stored ports: SAP batches through Ingesta, the seeded
| parameters and the stored settlements reproduce the Annex 2 figures.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-02 08:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->analyst = User::factory()->create(['email' => 'ana.gomez@segurex.com']);
});

it('settles code 14 of July 2026 from staged SAP sales and its budget, then approves and closes it', function () {
    $july = Period::of(2026, 7);

    $this->sap->send(Dataset::Sales, [
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['linea' => 1, 'total' => '9915134.00']),
        // Last days of June come along with the batch; they belong to June.
        SapBatches::sale(['linea' => 2, 'fecha_documento' => '2026-06-28', 'total' => '5000000.00']),
    ]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);

    $draft = app(CalculateSettlement::class)->handle($july, 'ana.gomez@segurex.com', new DateTimeImmutable('2026-08-03 09:00'));

    expect($draft->result->code(14)?->sales->amount())->toBe('69915134')
        ->and($draft->result->code(14)?->compliance->percent())->toBe('90.5146')
        ->and($draft->result->code(14)?->saleCommission->amount())->toBe('524364');

    app(ApproveSettlement::class)->handle($july, 'beto.ruiz@segurex.com', new DateTimeImmutable('2026-08-04 10:00'), $draft->revision);
    app(CloseSettlement::class)->handle($july, 'beto.ruiz@segurex.com', new DateTimeImmutable('2026-08-04 11:00'));

    $stored = app(Settlements::class)->latest($july);

    expect($stored?->status)->toBe(SettlementStatus::Closed)
        ->and($stored?->result->code(14)?->saleCommission->amount())->toBe('524364');
});

it('settles code 85 and its derived 85.1 of August 2026 from staged SAP payments', function () {
    $payment = fn (int $line, string $paid, string $date, string $invoiceDate) => SapBatches::payment([
        'doc_entry_pago' => 7100 + $line,
        'linea' => 0,
        'slp_code' => 85,
        'vendedor' => 'LILIAM HERRERA',
        'fecha_pago' => $date,
        'fecha_factura' => $invoiceDate,
        'valor_pagado_sin_iva' => $paid,
    ]);

    $this->sap->send(Dataset::Collections, [
        $payment(1, '2000000.00', '2026-08-10', '2026-07-31'),
        $payment(2, '1500000.00', '2026-08-12', '2026-06-03'),
        $payment(3, '1048738.00', '2026-08-18', '2026-05-10'),
        $payment(4, '1000000.00', '2026-08-25', '2026-02-06'),
    ], cutoff: '2026-08-31');

    $settlement = app(CalculateSettlement::class)->handle(Period::of(2026, 8), 'ana.gomez@segurex.com', new DateTimeImmutable('2026-09-02 08:00'));

    expect($settlement->result->code(85)?->collected->amount())->toBe('5548738')
        ->and($settlement->result->code(85)?->collectionCommission->amount())->toBe('83231')
        ->and($settlement->result->derived('85.1')?->commission->amount())->toBe('55487');
});
