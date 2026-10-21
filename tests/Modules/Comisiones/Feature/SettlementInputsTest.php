<?php

use App\Models\User;
use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Input\AdjustmentKind;
use App\Modules\Comisiones\Domain\Input\ClientAssignment;
use App\Modules\Comisiones\Domain\Input\CrossingLine;
use App\Modules\Comisiones\Domain\Input\CrossingSource;
use App\Modules\Comisiones\Domain\Input\CrossingType;
use App\Modules\Comisiones\Domain\Input\DocumentType;
use App\Modules\Comisiones\Domain\Input\PaymentLine;
use App\Modules\Comisiones\Domain\Input\SaleLine;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\OptionsVersion;
use App\Modules\Comisiones\Domain\Parameters\ParameterBook;
use App\Modules\Comisiones\Domain\Parameters\SettlementOptions;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Domain\Shared\Validity;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlementInputs;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| SettlementInputs: the staged SAP lines and the approved captures of a
| period, mapped to the engine's input (odd/tasks/comisiones-interfaz.md,
| "Mapping rules decided here").
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-01 07:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->sap = new SapBatches($this->fakes->ingesta);
    $this->july = Period::of(2026, 7);
    $this->analyst = User::factory()->create();
    $this->inputs = fn () => app(SettlementInputs::class)->forPeriod($this->july);
});

/**
 * @param  list<SaleLine|PaymentLine|CrossingLine>  $lines
 * @return array<string, SaleLine|PaymentLine|CrossingLine>
 */
function comisionesByKey(array $lines): array
{
    $byKey = [];

    foreach ($lines as $line) {
        $byKey[$line->key] = $line;
    }

    ksort($byKey);

    return $byKey;
}

it('is bound to the staging tables', function () {
    expect(app(SettlementInputs::class))->toBeInstanceOf(EloquentSettlementInputs::class);
});

it('nets in its own month the cancellation of a sale of an earlier month', function () {
    $this->sap->send(Dataset::Sales, [
        SapBatches::sale(['doc_entry' => 7001, 'fecha_documento' => '2026-07-20', 'estado_documento' => 'anulado', 'total' => '1000000.00']),
        SapBatches::sale(['doc_entry' => 7002, 'fecha_documento' => '2026-08-05', 'estado_documento' => 'anulacion', 'total' => '-1000000.00']),
    ]);

    $august = app(SettlementInputs::class)->forPeriod(Period::of(2026, 8))->sales;

    expect($august)->toHaveCount(1)
        ->and($august[0]->key)->toBe('13-7002-0')
        ->and($august[0]->annulled)->toBeFalse()
        ->and($august[0]->total->amount())->toBe('-1000000');
});

it('maps the sales of the period: annulments, credit notes, seller and currency', function () {
    $this->sap->send(Dataset::Sales, [
        SapBatches::sale(),
        SapBatches::sale(['linea' => 1, 'estado_documento' => 'anulado']),
        SapBatches::sale(['linea' => 2, 'estado_documento' => 'anulacion']),
        SapBatches::sale(['tipo_objeto' => 14, 'doc_entry' => 600, 'numero_documento' => 100, 'clase_documento' => 'nota_credito', 'total' => '-50000.00', 'moneda' => 'USD', 'trm' => '4000.000000']),
        SapBatches::sale(['linea' => 3, 'slp_code' => 0, 'vendedor' => 'OFICINA', 'moneda' => ' ']),
        SapBatches::sale(['linea' => 4, 'fecha_documento' => '2026-06-28']),
    ]);

    $sales = comisionesByKey(($this->inputs)()->sales);

    expect(array_keys($sales))->toBe(['13-5001-0', '13-5001-1', '13-5001-2', '13-5001-3', '14-600-0']);

    $invoice = $sales['13-5001-0'];

    expect($invoice->sellerCode)->toBe(14)
        ->and($invoice->sellerText)->toBe('14 MONICA RIVERA AREVALO')
        ->and($invoice->documentNumber)->toBe('8001')
        ->and($invoice->documentDate->format('Y-m-d'))->toBe('2026-07-15')
        ->and($invoice->documentType)->toBe(DocumentType::Invoice)
        ->and($invoice->clientCode)->toBe('C900123456')
        ->and($invoice->clientName)->toBe('Ferretería El Tornillo SAS')
        ->and($invoice->total->amount())->toBe('1000000')
        ->and($invoice->currency)->toBe('COP')
        ->and($invoice->exchangeRate)->toBe('1')
        ->and($invoice->annulled)->toBeFalse()
        // An original and its cancellation both count, signed, so they net to zero (§14.2 "netear").
        ->and($sales['13-5001-1']->annulled)->toBeFalse()
        ->and($sales['13-5001-2']->annulled)->toBeFalse()
        ->and($sales['14-600-0']->documentType)->toBe(DocumentType::CreditNote)
        ->and($sales['14-600-0']->total->amount())->toBe('-50000')
        // A USD invoice: the robot already sends its amounts in pesos (etl/queries/ventas.sql).
        ->and($sales['14-600-0']->currency)->toBe('COP')
        ->and($sales['14-600-0']->exchangeRate)->toBe('1')
        ->and($sales['13-5001-3']->sellerCode)->toBeNull()
        ->and($sales['13-5001-3']->sellerText)->toBe('0 OFICINA')
        ->and($sales['13-5001-3']->currency)->toBe('COP');
});

it('never converts a peso amount again when the options ask to convert foreign currency', function () {
    // SAP's document currency and rate stay in staging for reference only.
    $this->sap->send(Dataset::Sales, [SapBatches::sale(['doc_entry' => 5400, 'total' => '40000000.00', 'moneda' => 'USD', 'trm' => '4000.000000'])]);

    $book = DefaultParameters::book();
    $book = new ParameterBook($book->codes, $book->trancheTables, $book->teams, $book->derived, [
        new OptionsVersion(Validity::from('2026-01-01'), new SettlementOptions(currency: CurrencyTreatment::Convert)),
    ]);
    $result = (new SettlementCalculator)->calculate(($this->inputs)(), $book->forPeriod($this->july));

    expect($result->code(14)?->sales->amount())->toBe('40000000')
        ->and($result->alertsOf(AlertKind::ForeignCurrency))->toBe([]);
});

it('maps the payments of the period and finds the invoice date', function () {
    $this->sap->send(Dataset::Sales, [SapBatches::sale(['doc_entry' => 5100, 'numero_documento' => 8100, 'fecha_documento' => '2026-05-02'])]);
    $this->sap->send(Dataset::Collections, [
        SapBatches::payment(),
        SapBatches::payment(['linea' => 1, 'estado_pago' => 'anulado']),
        SapBatches::payment(['linea' => 2, 'doc_entry_factura' => 5100, 'numero_factura' => null, 'fecha_factura' => null]),
        SapBatches::payment(['linea' => 3, 'doc_entry_factura' => 5200, 'numero_factura' => null, 'fecha_factura' => null, 'moneda' => null, 'trm' => null]),
        SapBatches::payment(['linea' => 4, 'fecha_pago' => '2026-08-02']),
    ]);

    $payments = comisionesByKey(($this->inputs)()->payments);

    expect(array_keys($payments))->toBe(['7001-0', '7001-1', '7001-2', '7001-3']);

    $payment = $payments['7001-0'];

    expect($payment->sellerCode)->toBe(14)
        ->and($payment->sellerText)->toBe('14 MONICA RIVERA AREVALO')
        ->and($payment->paymentNumber)->toBe('3001')
        ->and($payment->paymentDate->format('Y-m-d'))->toBe('2026-07-20')
        ->and($payment->invoiceNumber)->toBe('8001')
        ->and($payment->invoiceDate->format('Y-m-d'))->toBe('2026-06-30')
        ->and($payment->invoiceAmount->amount())->toBe('1190000')
        ->and($payment->paidBase->amount())->toBe('420168.07')
        ->and($payment->annulled)->toBeFalse()
        ->and($payments['7001-1']->annulled)->toBeTrue()
        // Without its own invoice date: the staged invoice's date, else the payment date (0 days).
        ->and($payments['7001-2']->invoiceNumber)->toBe('5100')
        ->and($payments['7001-2']->invoiceDate->format('Y-m-d'))->toBe('2026-05-02')
        ->and($payments['7001-3']->invoiceDate->format('Y-m-d'))->toBe('2026-07-20')
        ->and($payments['7001-3']->currency)->toBe('COP')
        ->and($payments['7001-3']->exchangeRate)->toBe('1');

    expect(app(EloquentSettlementInputs::class)->paymentsWithoutInvoiceDate($this->july))->toBe(1);
});

it('maps the crossings of the period with their type, counterparty and approved override', function () {
    $this->sap->send(Dataset::Sales, [SapBatches::sale(['doc_entry' => 5300, 'moneda' => 'USD', 'trm' => '3950.500000'])]);
    $this->sap->send(Dataset::Offsets, [
        SapBatches::crossing(),
        SapBatches::crossing(['linea' => 1, 'tipo_cruce' => 'cliente-proveedor', 'codigo_proveedor' => 'P900123456', 'estado' => 'anulado']),
        SapBatches::crossing(['linea' => 2, 'tipo_cruce' => 'otro', 'nit' => null, 'doc_entry_factura' => 5300, 'fecha_factura' => null, 'base_gravable' => null, 'iva_factura' => null]),
        SapBatches::crossing(['linea' => 3, 'fecha_cruce' => '2026-06-30']),
    ]);

    // Reassignments of sap-9001-0: the latest approved one wins; pending and rejected ones never count.
    ReassignmentRecord::query()->create(['clave_cruce' => 'sap-9001-0', 'slp_code' => 15, 'motivo' => 'Primera', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id, 'revisado_en' => '2026-07-25 10:00:00']);
    ReassignmentRecord::query()->create(['clave_cruce' => 'sap-9001-0', 'slp_code' => 41, 'motivo' => 'Corrección', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id, 'revisado_en' => '2026-07-26 10:00:00']);
    ReassignmentRecord::query()->create(['clave_cruce' => 'sap-9001-0', 'slp_code' => 16, 'motivo' => 'Pendiente', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id]);
    ReassignmentRecord::query()->create(['clave_cruce' => 'sap-9001-2', 'slp_code' => 16, 'motivo' => 'Rechazada', 'estado' => CaptureStatus::Rejected, 'created_by' => $this->analyst->id]);

    $crossings = comisionesByKey(($this->inputs)()->crossings);

    expect(array_keys($crossings))->toBe(['sap-9001-0', 'sap-9001-1', 'sap-9001-2']);

    $advance = $crossings['sap-9001-0'];

    expect($advance->type)->toBe(CrossingType::Advance)
        ->and($advance->source)->toBe(CrossingSource::Sap)
        ->and($advance->documentNumber)->toBe('9001')
        ->and($advance->crossingDate->format('Y-m-d'))->toBe('2026-07-22')
        ->and($advance->invoiceNumber)->toBe('8001')
        ->and($advance->invoiceDate->format('Y-m-d'))->toBe('2026-06-30')
        ->and($advance->clientCode)->toBe('C900123456')
        ->and($advance->clientName)->toBe('Ferretería El Tornillo SAS')
        ->and($advance->counterparty)->toBe('900123456')
        ->and($advance->invoiceAmount->amount())->toBe('1190000')
        ->and($advance->crossedAmount->amount())->toBe('1190000')
        ->and($advance->invoiceTaxBase?->amount())->toBe('1000000')
        ->and($advance->invoiceVat?->amount())->toBe('190000')
        ->and($advance->crossedAmountWithoutVat?->amount())->toBe('1000000')
        ->and($advance->sellerOverride)->toBe(41)
        ->and($advance->currency)->toBe('COP')
        ->and($advance->annulled)->toBeFalse();

    expect($crossings['sap-9001-1']->type)->toBe(CrossingType::ClientSupplier)
        ->and($crossings['sap-9001-1']->counterparty)->toBe('P900123456')
        ->and($crossings['sap-9001-1']->annulled)->toBeTrue()
        ->and($crossings['sap-9001-2']->type)->toBe(CrossingType::Other)
        ->and($crossings['sap-9001-2']->counterparty)->toBe('')
        ->and($crossings['sap-9001-2']->sellerOverride)->toBeNull()
        ->and($crossings['sap-9001-2']->invoiceTaxBase)->toBeNull()
        // In pesos even for a USD invoice, and the invoice's date when the crossing has none.
        ->and($crossings['sap-9001-2']->currency)->toBe('COP')
        ->and($crossings['sap-9001-2']->exchangeRate)->toBe('1')
        ->and($crossings['sap-9001-2']->invoiceDate->format('Y-m-d'))->toBe('2026-07-15');
});

it('adds only the approved manual crossings, with the seller the user captured', function () {
    $manual = fn (array $attributes) => CrossingRecord::query()->create(array_replace([
        'clave' => 'manual-'.fake()->uuid(),
        'origen' => 'manual',
        'fecha_cruce' => '2026-07-18',
        'tipo_cruce' => 'anticipo',
        'estado_cruce' => 'vigente',
        'codigo_cliente' => 'C77',
        'razon_social' => 'Cliente manual SAS',
        'numero_factura' => 'FV-77',
        'fecha_factura' => '2026-07-01',
        'valor_factura' => '119000.00',
        'valor_compensado' => '119000.00',
        'valor_compensado_sin_iva' => '100000.00',
        'slp_code_capturado' => 21,
        'soporte' => 'comisiones/soportes/acta.pdf',
        'estado' => CaptureStatus::Approved,
        'created_by' => $this->analyst->id,
    ], $attributes));

    $approved = $manual([]);
    $manual(['estado' => CaptureStatus::Pending]);
    $manual(['estado' => CaptureStatus::Rejected]);

    $crossings = ($this->inputs)()->crossings;

    expect($crossings)->toHaveCount(1)
        ->and($crossings[0]->key)->toBe($approved->clave)
        ->and($crossings[0]->source)->toBe(CrossingSource::Manual)
        ->and($crossings[0]->sellerOverride)->toBe(21)
        ->and($crossings[0]->invoiceNumber)->toBe('FV-77')
        ->and($crossings[0]->documentNumber)->toBe($approved->clave);
});

it('sends the whole assignment history', function () {
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client()], cutoff: '2026-05-01');
    $this->sap->send(Dataset::CustomerMaster, [SapBatches::client(['slp_code' => 15])], cutoff: '2026-09-01');

    $assignments = ($this->inputs)()->assignments;

    expect(array_map(fn (ClientAssignment $a) => [$a->clientCode, $a->sellerCode, $a->effectiveFrom->format('Y-m-d')], $assignments))
        ->toBe([['C900123456', 14, '2000-01-01'], ['C900123456', 15, '2026-09-01']]);
});

it('sends the budgets of the period and only its approved OBR sales and adjustments', function () {
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);
    BudgetRecord::query()->create(['periodo' => '2026-08', 'slp_code' => 14, 'valor' => '1.00', 'created_by' => $this->analyst->id]);

    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 33, 'descripcion' => 'Escudos obra norte', 'valor_vendido' => '1000000.00', 'tasa' => '2.5', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id]);
    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 33, 'descripcion' => 'Pendiente', 'valor_vendido' => '5.00', 'tasa' => '1', 'estado' => CaptureStatus::Pending, 'created_by' => $this->analyst->id]);

    AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'descuento', 'valor' => '20000.00', 'descripcion' => 'Anticipo de nómina', 'estado' => CaptureStatus::Approved, 'created_by' => $this->analyst->id]);
    AdjustmentRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'tipo' => 'reintegro', 'valor' => '9.00', 'descripcion' => 'Rechazado', 'estado' => CaptureStatus::Rejected, 'created_by' => $this->analyst->id]);

    $input = ($this->inputs)();

    expect($input->period)->toEqual($this->july)
        ->and($input->budgets)->toHaveCount(1)
        ->and($input->budgets[0]->sellerCode)->toBe(14)
        ->and($input->budgets[0]->amount->amount())->toBe('77241857')
        ->and($input->obrSales)->toHaveCount(1)
        ->and($input->obrSales[0]->description)->toBe('Escudos obra norte')
        ->and($input->obrSales[0]->soldValue->amount())->toBe('1000000')
        ->and($input->obrSales[0]->rate->percent())->toBe('2.5')
        ->and($input->adjustments)->toHaveCount(1)
        ->and($input->adjustments[0]->kind)->toBe(AdjustmentKind::Deduction)
        ->and($input->adjustments[0]->amount->amount())->toBe('20000')
        ->and($input->adjustments[0]->description)->toBe('Anticipo de nómina');
});
