<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Domain\PaymentMethod;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Modules\Soportes\Infrastructure\Models\ReceiptFile;
use App\Modules\Soportes\Infrastructure\Models\ReceiptInvoice;
use Carbon\CarbonImmutable;

it('stores a receipt with its invoices and files and reads them back', function () {
    $uploader = User::factory()->create();

    $receipt = Receipt::query()->create([
        'company' => CompanyCode::SGX,
        'client_id' => 41,
        'client_code' => 'C900000041',
        'client_name' => 'Ferretería El Tornillo SAS',
        'advisor_code' => 10,
        'amount' => '1250000.50',
        'paid_at' => '2026-11-18',
        'method' => PaymentMethod::Transfer,
        'note' => 'Pago parcial',
        'uploaded_by' => $uploader->id,
    ]);

    $receipt->invoices()->create([
        'document_number' => 'FV-1001',
        'document_type' => 'Factura',
        'due_at' => '2026-10-01',
        'balance' => '1250000.50',
        'days_overdue' => 48,
    ]);

    $receipt->files()->create([
        'path' => 'soportes/SGX/2026/11/0b6f8a1e-6c4b-4a8e-9d3c-2f1e7a5b9c10.pdf',
        'original_name' => 'comprobante.pdf',
        'mime' => 'application/pdf',
        'size' => 48213,
    ]);

    $stored = Receipt::query()->with(['invoices', 'files', 'uploader', 'reviewer'])->findOrFail($receipt->id);

    expect($stored->company)->toBe(CompanyCode::SGX)
        ->and($stored->status)->toBe(ReceiptStatus::Pending)
        ->and($stored->method)->toBe(PaymentMethod::Transfer)
        ->and($stored->amount)->toBe('1250000.50')
        ->and($stored->paid_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($stored->paid_at->toDateString())->toBe('2026-11-18')
        ->and($stored->uploader->is($uploader))->toBeTrue()
        ->and($stored->reviewer)->toBeNull()
        ->and($stored->invoices)->toHaveCount(1)
        ->and($stored->invoices->first())->toBeInstanceOf(ReceiptInvoice::class)
        ->and($stored->invoices->first()->document_number)->toBe('FV-1001')
        ->and($stored->invoices->first()->balance)->toBe('1250000.50')
        ->and($stored->files->first())->toBeInstanceOf(ReceiptFile::class)
        ->and($stored->files->first()->size)->toBe(48213);
});

it('allows a receipt without a payment method', function () {
    $receipt = Receipt::factory()->create(['method' => null]);

    expect($receipt->fresh()?->method)->toBeNull();
});

it('deletes the invoices and files of a deleted receipt', function () {
    $receipt = Receipt::factory()->create();
    $receipt->invoices()->create([
        'document_number' => 'FV-1', 'document_type' => 'Factura', 'due_at' => '2026-10-01', 'balance' => '10.00', 'days_overdue' => 5,
    ]);
    $receipt->files()->create([
        'path' => 'soportes/SGX/2026/11/a.pdf', 'original_name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 1,
    ]);

    $receipt->delete();

    expect(ReceiptInvoice::query()->count())->toBe(0)
        ->and(ReceiptFile::query()->count())->toBe(0);
});

it('filters pending receipts and receipts of some companies', function () {
    $pendingSgx = Receipt::factory()->create(['company' => CompanyCode::SGX]);
    Receipt::factory()->applied()->create(['company' => CompanyCode::SGX]);
    $pendingSdc = Receipt::factory()->create(['company' => CompanyCode::SDC]);

    expect(Receipt::query()->pending()->pluck('id')->sort()->values()->all())->toBe([$pendingSgx->id, $pendingSdc->id])
        ->and(Receipt::query()->pending()->forCompanies([CompanyCode::SDC])->pluck('id')->all())->toBe([$pendingSdc->id])
        ->and(Receipt::query()->forCompanies([])->count())->toBe(0);
});

it('reads the storage settings from the module config', function () {
    expect(config('soportes.disk'))->toBe('local')
        ->and(config('soportes.directory'))->toBe('soportes')
        ->and(config('soportes.max_files'))->toBe(3)
        ->and(config('soportes.max_file_kilobytes'))->toBe(10240)
        ->and(config('soportes.allowed_extensions'))->toBe(['pdf', 'jpg', 'jpeg', 'png'])
        ->and(config('soportes.allowed_mimetypes'))->toBe(['application/pdf', 'image/jpeg', 'image/png']);
});
