<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Infrastructure\Ingest\ImportAdvisors;
use App\Modules\Core\Infrastructure\Ingest\ImportReceivablesSnapshot;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Carbon\CarbonImmutable;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Queue;
use Tests\Modules\Core\Support\RobotBatches;

beforeEach(function () {
    $this->robot = RobotBatches::install($this->app);
});

/**
 * The client of $company with SAP code $code, also when it is soft-deleted.
 */
function importedClient(string $code, CompanyCode $company = CompanyCode::SGX): ClientRecord
{
    return ClientRecord::withTrashed()->where('company', $company)->where('code', $code)->sole();
}

it('turns a cartera batch into a dated snapshot of invoices', function () {
    $load = $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable([
            'documento' => '104233', 'no_plazo' => 1, 'fecha_documento' => '2026-08-20', 'fecha_contabilizacion' => '2026-08-21',
            'fecha_vencimiento' => '2026-09-01', 'importe_original' => '1234567.89', 'saldo' => '1234567.89',
        ]),
        RobotBatches::receivable([
            'documento' => '104300', 'no_plazo' => 0, 'fecha_documento' => '2026-09-20',
            'fecha_vencimiento' => '2026-10-20', 'importe_original' => '500000.10', 'saldo' => '0.10',
        ]),
        RobotBatches::receivable([
            'codigo_sn' => 'C800197268', 'nombre_sn' => 'Distribuciones Arango S.A.S.', 'nit' => '800197268-4',
            'tipo_doc' => '14', 'documento' => '2201', 'fecha_documento' => '2026-10-10', 'fecha_vencimiento' => '2026-10-15',
            'importe_original' => '-300000.00', 'saldo' => '-300000.00', 'slp_code' => 7,
        ]),
    ]);

    $snapshot = PortfolioLoadRecord::query()->sole();

    expect($snapshot->company)->toBe(CompanyCode::SGX)
        ->and($snapshot->cutoff_date->toDateString())->toBe('2026-10-15')
        ->and($snapshot->load_id)->toBe($load->id)
        ->and($snapshot->source)->toBe(LoadSource::Robot)
        ->and($snapshot->invoice_count)->toBe(3)
        ->and($snapshot->total)->toBe('934567.99')
        ->and($snapshot->overdue)->toBe('1234567.89');

    $invoices = InvoiceRecord::query()->with('client')->orderBy('id')->get()->map(fn (InvoiceRecord $invoice): array => [
        $invoice->portfolio_load_id, $invoice->company, $invoice->client->code, $invoice->document_number, $invoice->document_type,
        $invoice->installment, $invoice->issued_at->toDateString(), $invoice->due_at->toDateString(),
        $invoice->amount, $invoice->balance, $invoice->days_overdue, $invoice->advisor_code,
    ])->all();

    expect($invoices)->toBe([
        [$snapshot->id, CompanyCode::SGX, 'C900123456', '104233', '13', 1, '2026-08-20', '2026-09-01', '1234567.89', '1234567.89', 44, 12],
        [$snapshot->id, CompanyCode::SGX, 'C900123456', '104300', '13', 0, '2026-09-20', '2026-10-20', '500000.10', '0.10', -5, 12],
        [$snapshot->id, CompanyCode::SGX, 'C800197268', '2201', '14', 0, '2026-10-10', '2026-10-15', '-300000.00', '-300000.00', 0, 7],
    ]);

    $ferreteria = importedClient('C900123456');

    expect($ferreteria->name)->toBe('Ferretería El Tornillo S.A.S.')
        ->and($ferreteria->tax_id)->toBe('900123456-8')
        ->and($ferreteria->group)->toBe('Ferreterías')
        ->and($ferreteria->city)->toBe('Medellín')
        ->and($ferreteria->payment_terms)->toBe('30 días')
        ->and($ferreteria->credit_limit)->toBe('50000000.00')
        ->and($ferreteria->advisor_code)->toBe(12)
        ->and($ferreteria->status)->toBe(ClientStatus::Overdue)
        ->and($ferreteria->balance)->toBe('1234567.99')
        ->and($ferreteria->overdue)->toBe('1234567.89')
        ->and($ferreteria->max_days_overdue)->toBe(44);

    // A credit note alone is no debt: the client stays current.
    $arango = importedClient('C800197268');

    expect($arango->name)->toBe('Distribuciones Arango S.A.S.')
        ->and($arango->advisor_code)->toBe(7)
        ->and($arango->status)->toBe(ClientStatus::Current)
        ->and($arango->balance)->toBe('-300000.00')
        ->and($arango->overdue)->toBe('0.00')
        ->and($arango->max_days_overdue)->toBe(0);
});

it('stores blank SAP text as no value and SAP\'s "no sales employee" as no advisor', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['slp_code' => -1, 'grupo' => '', 'municipio' => '  ', 'condicion_pago' => null, 'limite_credito' => null]),
    ]);

    $client = importedClient('C900123456');

    expect($client->advisor_code)->toBeNull()
        ->and($client->group)->toBeNull()
        ->and($client->city)->toBeNull()
        ->and($client->payment_terms)->toBeNull()
        ->and($client->credit_limit)->toBeNull()
        ->and(InvoiceRecord::query()->sole()->advisor_code)->toBeNull();
});

it('falls back to the days overdue and the other dates when SAP leaves a date out', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable([
            'documento' => '1', 'fecha_vencimiento' => null, 'dias' => 20, 'fecha_documento' => null, 'fecha_contabilizacion' => '2026-09-10',
        ]),
        RobotBatches::receivable([
            'documento' => '2', 'fecha_vencimiento' => null, 'dias' => -3, 'fecha_documento' => null, 'fecha_contabilizacion' => null,
        ]),
    ]);

    $invoices = InvoiceRecord::query()->orderBy('document_number')->get()->map(fn (InvoiceRecord $invoice): array => [
        $invoice->issued_at->toDateString(), $invoice->due_at->toDateString(), $invoice->days_overdue,
    ])->all();

    expect($invoices)->toBe([
        ['2026-09-10', '2026-09-25', 20],
        ['2026-10-18', '2026-10-18', -3],
    ]);
});

it('keeps every snapshot and refreshes the clients with the newest one', function () {
    $first = $this->robot->receivables(CompanyCode::SGX, '2026-10-14', [
        RobotBatches::receivable(['documento' => '100', 'saldo' => '1000.00']),
    ]);
    $second = $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable([
            'documento' => '200', 'saldo' => '2000.00', 'nombre_sn' => 'Ferretería El Tornillo y Cía. S.A.S.',
            'limite_credito' => '80000000.00', 'slp_code' => 7,
        ]),
    ]);

    $snapshots = PortfolioLoadRecord::query()->with('invoices')->orderBy('cutoff_date')->get();

    expect($snapshots->pluck('load_id')->all())->toBe([$first->id, $second->id])
        ->and($snapshots[0]->invoices->pluck('document_number')->all())->toBe(['100'])
        ->and($snapshots[0]->invoices->pluck('balance')->all())->toBe(['1000.00'])
        ->and($snapshots[1]->invoices->pluck('document_number')->all())->toBe(['200'])
        ->and(ClientRecord::query()->count())->toBe(1);

    $client = importedClient('C900123456');

    expect($client->name)->toBe('Ferretería El Tornillo y Cía. S.A.S.')
        ->and($client->credit_limit)->toBe('80000000.00')
        ->and($client->advisor_code)->toBe(7)
        ->and($client->balance)->toBe('2000.00');
});

it('changes neither the clients nor their status with a snapshot older than the latest one', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['fecha_vencimiento' => '2026-09-01', 'saldo' => '1000.00']),
    ]);
    $late = $this->robot->receivables(CompanyCode::SGX, '2026-10-10', [
        RobotBatches::receivable(['nombre_sn' => 'Nombre viejo', 'fecha_vencimiento' => '2026-10-30', 'saldo' => '5.00']),
        RobotBatches::receivable(['codigo_sn' => 'C800197268', 'nombre_sn' => 'Cliente solo en el corte viejo', 'saldo' => '7.00']),
    ]);

    $client = importedClient('C900123456');

    expect(PortfolioLoadRecord::query()->where('load_id', $late->id)->sole()->invoices()->count())->toBe(2)
        ->and($client->name)->toBe('Ferretería El Tornillo S.A.S.')
        ->and($client->status)->toBe(ClientStatus::Overdue)
        ->and($client->balance)->toBe('1000.00')
        ->and(importedClient('C800197268')->name)->toBe('Cliente solo en el corte viejo')
        ->and(importedClient('C800197268')->balance)->toBe('0.00');
});

it('imports each load once', function () {
    $load = $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);

    event(new BatchAccepted($load));

    expect(PortfolioLoadRecord::query()->count())->toBe(1)
        ->and(InvoiceRecord::query()->count())->toBe(1)
        ->and(ClientRecord::query()->count())->toBe(1);
});

it('leaves owing nothing a client missing from the latest snapshot', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['codigo_sn' => 'C-VENCIDO', 'fecha_vencimiento' => '2026-09-01']),
        RobotBatches::receivable(['codigo_sn' => 'C-AL-DIA', 'fecha_vencimiento' => '2026-12-31']),
    ]);

    expect(importedClient('C-VENCIDO')->status)->toBe(ClientStatus::Overdue)
        ->and(importedClient('C-AL-DIA')->status)->toBe(ClientStatus::Current);

    $this->robot->receivables(CompanyCode::SGX, '2026-10-16', [
        RobotBatches::receivable(['codigo_sn' => 'C-OTRO']),
    ]);

    $paid = importedClient('C-VENCIDO');
    $current = importedClient('C-AL-DIA');

    expect($paid->status)->toBe(ClientStatus::PaymentReceived)
        ->and($paid->balance)->toBe('0.00')
        ->and($paid->overdue)->toBe('0.00')
        ->and($paid->max_days_overdue)->toBe(0)
        ->and($current->status)->toBe(ClientStatus::Current)
        ->and($current->balance)->toBe('0.00');
});

it('keeps a manual status while the client owes and drops it once paid', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['codigo_sn' => 'C-GESTION', 'fecha_vencimiento' => '2026-09-01']),
        RobotBatches::receivable(['codigo_sn' => 'C-JURIDICO', 'fecha_vencimiento' => '2026-09-01']),
    ]);
    importedClient('C-GESTION')->update(['status' => ClientStatus::InProgress]);
    importedClient('C-JURIDICO')->update(['status' => ClientStatus::Legal]);

    $this->robot->receivables(CompanyCode::SGX, '2026-10-16', [
        RobotBatches::receivable(['codigo_sn' => 'C-GESTION', 'fecha_vencimiento' => '2026-09-01']),
    ]);

    expect(importedClient('C-GESTION')->status)->toBe(ClientStatus::InProgress)
        ->and(importedClient('C-JURIDICO')->status)->toBe(ClientStatus::PaymentReceived);
});

it('counts the grace days of each company after the due date', function (CompanyCode $company, int $graceDays) {
    $cutoff = CarbonImmutable::parse('2026-10-15');

    $this->robot->receivables($company, $cutoff->toDateString(), [
        RobotBatches::receivable(['codigo_sn' => 'C-GRACIA', 'fecha_vencimiento' => $cutoff->subDays($graceDays)->toDateString()]),
        RobotBatches::receivable(['codigo_sn' => 'C-VENCIDO', 'fecha_vencimiento' => $cutoff->subDays($graceDays + 1)->toDateString()]),
    ]);

    expect(importedClient('C-GRACIA', $company)->status)->toBe(ClientStatus::DueSoon)
        ->and(importedClient('C-GRACIA', $company)->max_days_overdue)->toBe($graceDays)
        ->and(importedClient('C-VENCIDO', $company)->status)->toBe(ClientStatus::Overdue);
})->with([
    'Segurex, 15 days' => [CompanyCode::SGX, 15],
    'Derivados, 5 days' => [CompanyCode::SDC, 5],
]);

it('reads the grace days from the companies table', function () {
    CompanyRecord::query()->whereKey('SGX')->update(['grace_days' => 20]);

    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['codigo_sn' => 'C-GRACIA', 'fecha_vencimiento' => '2026-09-25']),
        RobotBatches::receivable(['codigo_sn' => 'C-VENCIDO', 'fecha_vencimiento' => '2026-09-24']),
    ]);

    expect(importedClient('C-GRACIA')->status)->toBe(ClientStatus::DueSoon)
        ->and(importedClient('C-VENCIDO')->status)->toBe(ClientStatus::Overdue);
});

it('audits every status change', function () {
    $this->freezeTime();

    $load = $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['codigo_sn' => 'C-VENCIDO', 'fecha_vencimiento' => '2026-09-01']),
        RobotBatches::receivable(['codigo_sn' => 'C-AL-DIA', 'fecha_vencimiento' => '2026-12-31']),
    ]);

    $overdue = importedClient('C-VENCIDO');
    $entry = AuditLogRecord::query()->where('action', 'core.client.status_changed')->sole();

    expect($entry->subject_type)->toBe('client')
        ->and($entry->subject_id)->toBe((string) $overdue->id)
        ->and($entry->changes)->toEqual(['status' => ['old' => 'al_dia', 'new' => 'vencida']])
        ->and($entry->context)->toEqual(['load_id' => $load->id, 'cutoff_date' => '2026-10-15'])
        ->and($entry->user_id)->toBeNull()
        ->and($overdue->status_changed_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(importedClient('C-AL-DIA')->status_changed_at)->toBeNull();

    // The same debt the next day changes no status: nothing new is audited.
    $this->robot->receivables(CompanyCode::SGX, '2026-10-16', [
        RobotBatches::receivable(['codigo_sn' => 'C-VENCIDO', 'fecha_vencimiento' => '2026-09-01']),
        RobotBatches::receivable(['codigo_sn' => 'C-AL-DIA', 'fecha_vencimiento' => '2026-12-31']),
    ]);

    expect(AuditLogRecord::query()->where('action', 'core.client.status_changed')->count())->toBe(1);
});

it('refreshes a soft-deleted client and gives it its invoices without restoring it', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);
    importedClient('C900123456')->delete();

    $this->robot->receivables(CompanyCode::SGX, '2026-10-16', [
        RobotBatches::receivable(['nombre_sn' => 'Ferretería El Tornillo 2 S.A.S.', 'documento' => '555', 'fecha_vencimiento' => '2026-09-01']),
    ]);

    $client = importedClient('C900123456');
    $latest = PortfolioLoadRecord::query()->orderByDesc('cutoff_date')->firstOrFail();

    expect($client->trashed())->toBeTrue()
        ->and(ClientRecord::query()->count())->toBe(0)
        ->and(ClientRecord::withTrashed()->count())->toBe(1)
        ->and($client->name)->toBe('Ferretería El Tornillo 2 S.A.S.')
        ->and($client->status)->toBe(ClientStatus::Overdue)
        ->and($latest->invoices()->sole()->client_id)->toBe($client->id);
});

it('touches only the company of the batch', function () {
    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [
        RobotBatches::receivable(['fecha_vencimiento' => '2026-09-01', 'saldo' => '1000.00']),
    ]);

    $this->robot->receivables(CompanyCode::SDC, '2026-10-16', [
        RobotBatches::receivable(['nombre_sn' => 'Ferretería El Tornillo (Derivados)', 'saldo' => '25.00']),
    ]);

    $segurex = importedClient('C900123456', CompanyCode::SGX);
    $derivados = importedClient('C900123456', CompanyCode::SDC);

    expect($segurex->name)->toBe('Ferretería El Tornillo S.A.S.')
        ->and($segurex->status)->toBe(ClientStatus::Overdue)
        ->and($segurex->balance)->toBe('1000.00')
        ->and($derivados->name)->toBe('Ferretería El Tornillo (Derivados)')
        ->and($derivados->balance)->toBe('25.00')
        ->and(InvoiceRecord::query()->where('company', CompanyCode::SDC)->sole()->client_id)->toBe($derivados->id);
});

it('ignores the batches of other datasets', function (Dataset $dataset) {
    $this->robot->send($dataset, CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);

    expect(PortfolioLoadRecord::query()->count())->toBe(0)
        ->and(ClientRecord::withTrashed()->count())->toBe(0);
})->with([Dataset::Advisors, Dataset::Contacts, Dataset::Sales]);

it('queues the import of cartera batches only', function () {
    Queue::fake();

    $this->robot->send(Dataset::Contacts, CompanyCode::SGX, '2026-10-15', []);

    Queue::assertNothingPushed();

    $this->robot->receivables(CompanyCode::SGX, '2026-10-15', [RobotBatches::receivable()]);

    Queue::assertPushed(CallQueuedListener::class, 1);
    Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ImportReceivablesSnapshot::class);
    Queue::assertNotPushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === ImportAdvisors::class);
});
