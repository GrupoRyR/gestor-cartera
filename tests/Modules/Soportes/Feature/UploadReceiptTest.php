<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Application\Upload\NewReceipt;
use App\Modules\Soportes\Application\Upload\UploadReceipt;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use App\Modules\Soportes\Domain\Exceptions\ReceiptRefused;
use App\Modules\Soportes\Domain\PaymentMethod;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Modules\Soportes\Infrastructure\Models\ReceiptFile;
use App\Modules\Soportes\Infrastructure\Models\ReceiptInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Soportes\Support\OverdueClient;
use Tests\Modules\Soportes\Support\SoportesUsers;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    Storage::fake('local');
});

/**
 * A receipt for $clientId covering $documents, with a PDF unless $files
 * says otherwise.
 *
 * @param  list<string>  $documents
 * @param  list<UploadedFile>|null  $files
 */
function newReceipt(int $clientId, array $documents = ['FV-1001'], ?array $files = null): NewReceipt
{
    return new NewReceipt(
        clientId: $clientId,
        documentNumbers: $documents,
        amount: '1250000.50',
        paidAt: CarbonImmutable::parse('2026-11-18'),
        files: $files ?? [UploadedFile::fake()->create('comprobante cliente.pdf', 120, 'application/pdf')],
        method: PaymentMethod::Transfer,
        note: 'Pago parcial de dos facturas',
    );
}

it('stores a pending receipt with copies of the client and its open invoices', function () {
    $client = OverdueClient::add($this->fakes->cartera, advisorCode: 10);
    $advisor = SoportesUsers::advisor();

    $receipt = app(UploadReceipt::class)->handle(newReceipt($client->id, ['FV-1001', 'FV-1002']), $advisor);

    $receipt = Receipt::query()->with('invoices')->findOrFail($receipt->id);

    expect($receipt->status)->toBe(ReceiptStatus::Pending)
        ->and($receipt->company)->toBe(CompanyCode::SGX)
        ->and($receipt->client_id)->toBe($client->id)
        ->and($receipt->client_code)->toBe($client->code)
        ->and($receipt->client_name)->toBe('Ferretería El Tornillo SAS')
        ->and($receipt->advisor_code)->toBe(10)
        ->and($receipt->amount)->toBe('1250000.50')
        ->and($receipt->paid_at->format('Y-m-d'))->toBe('2026-11-18')
        ->and($receipt->method)->toBe(PaymentMethod::Transfer)
        ->and($receipt->note)->toBe('Pago parcial de dos facturas')
        ->and($receipt->uploaded_by)->toBe($advisor->id)
        ->and($receipt->invoices->map(fn (ReceiptInvoice $invoice): array => [
            $invoice->document_number, $invoice->document_type, $invoice->due_at->format('Y-m-d'), $invoice->balance, $invoice->days_overdue,
        ])->all())->toBe([
            ['FV-1001', 'Factura', '2026-10-06', '1200000.00', 45],
            ['FV-1002', 'Factura', '2026-11-10', '800000.00', 10],
        ]);
});

it('stores the files on the private disk under generated names, never the uploaded one', function () {
    $client = OverdueClient::add($this->fakes->cartera);

    $receipt = app(UploadReceipt::class)->handle(newReceipt($client->id, files: [
        UploadedFile::fake()->create('comprobante cliente.pdf', 120, 'application/pdf'),
        UploadedFile::fake()->image('foto del pago.jpeg'),
    ]), SoportesUsers::advisor());

    $files = ReceiptFile::query()->where('soporte_id', $receipt->id)->orderBy('id')->get();

    expect($files)->toHaveCount(2)
        ->and($files[0]->path)->toMatch('#^soportes/SGX/2026/11/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#')
        ->and($files[0]->original_name)->toBe('comprobante cliente.pdf')
        ->and($files[0]->mime)->toBe('application/pdf')
        ->and($files[0]->size)->toBe(120 * 1024)
        ->and($files[1]->path)->toMatch('#^soportes/SGX/2026/11/[0-9a-f-]{36}\.jpg$#')
        ->and($files[1]->mime)->toBe('image/jpeg');

    Storage::disk('local')->assertExists($files[0]->path);
    Storage::disk('local')->assertExists($files[1]->path);

    expect(implode("\n", Storage::disk('local')->allFiles()))->not->toContain('comprobante')->not->toContain('foto');
});

it('copies every open installment of a document with split payment terms, due or not', function () {
    $summary = Make::client(overdue: 900_000.0, maxDaysOverdue: 40, advisor: Make::advisor(code: 10));
    $this->fakes->cartera->addClient(
        Make::clientDetail($summary),
        Make::invoice(clientId: $summary->id, documentNumber: 'FV-2001', amount: 400_000.0, daysOverdue: 40),
        Make::invoice(clientId: $summary->id, documentNumber: 'FV-2001', amount: 500_000.0, daysOverdue: 10),
        Make::invoice(clientId: $summary->id, documentNumber: 'FV-2001', amount: 600_000.0, daysOverdue: -20),
    );

    $receipt = app(UploadReceipt::class)->handle(newReceipt($summary->id, ['FV-2001']), SoportesUsers::advisor());

    expect($receipt->invoices()->orderBy('id')->pluck('days_overdue')->all())->toBe([40, 10, -20]);
});

it('takes invoices that are not due yet or due today', function () {
    $summary = Make::client(balance: 800_000.0, advisor: Make::advisor(code: 10));
    $this->fakes->cartera->addClient(
        Make::clientDetail($summary),
        Make::invoice(clientId: $summary->id, documentNumber: 'FV-4001', amount: 500_000.0, daysOverdue: -5),
        Make::invoice(clientId: $summary->id, documentNumber: 'FV-4002', amount: 300_000.0, daysOverdue: 0),
    );

    $receipt = app(UploadReceipt::class)->handle(newReceipt($summary->id, ['FV-4001', 'FV-4002']), SoportesUsers::advisor());

    expect($receipt->invoices()->orderBy('document_number')->pluck('days_overdue', 'document_number')->all())
        ->toBe(['FV-4001' => -5, 'FV-4002' => 0]);
});

it('refuses a client outside the advisor scope and stores nothing', function () {
    $client = OverdueClient::add($this->fakes->cartera, advisorCode: 10);
    $this->fakes->cartera->restrictToAdvisor(20);

    expect(fn () => app(UploadReceipt::class)->handle(newReceipt($client->id), SoportesUsers::advisor()))
        ->toThrow(fn (ReceiptRefused $e) => expect($e->field)->toBe('cliente')->and($e->reason)->toBe(ReceiptRefused::CLIENT_NOT_FOUND));

    expect(Receipt::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $this->fakes->audit->assertNothingRecorded();
});

it('refuses a document that is not an open invoice of that client', function (string $document) {
    $client = OverdueClient::add($this->fakes->cartera);
    OverdueClient::add($this->fakes->cartera, name: 'Otro Cliente SAS');

    expect(fn () => app(UploadReceipt::class)->handle(newReceipt($client->id, ['FV-1001', $document]), SoportesUsers::advisor()))
        ->toThrow(fn (ReceiptRefused $e) => expect($e->field)->toBe('facturas')->and($e->reason)->toBe(ReceiptRefused::INVOICE_NOT_OPEN));

    expect(Receipt::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'a credit note' => 'NC-1001',
    'unknown' => 'FV-9999',
]);

it('refuses an invoice of another client', function () {
    $client = OverdueClient::add($this->fakes->cartera);
    $other = Make::client(overdue: 300_000.0, advisor: Make::advisor(code: 10));
    $this->fakes->cartera->addClient(Make::clientDetail($other), Make::invoice(clientId: $other->id, documentNumber: 'FV-3001', daysOverdue: 30));

    expect(fn () => app(UploadReceipt::class)->handle(newReceipt($client->id, ['FV-3001']), SoportesUsers::advisor()))
        ->toThrow(ReceiptRefused::class);
});

it('records the upload in the audit log with ids, counts and the amount only', function () {
    $client = OverdueClient::add($this->fakes->cartera);

    $receipt = app(UploadReceipt::class)->handle(newReceipt($client->id, ['FV-1001', 'FV-1002']), SoportesUsers::advisor());

    $this->fakes->audit->assertRecorded('soportes.receipt.uploaded', fn (AuditEntry $entry): bool => $entry->subjectType === 'soporte'
        && $entry->subjectId === $receipt->id
        && $entry->changes === [
            'company' => 'SGX',
            'client_id' => $client->id,
            'amount' => '1250000.50',
            'paid_at' => '2026-11-18',
            'method' => 'transferencia',
            'status' => 'pendiente',
        ]
        && $entry->context === ['invoices' => 2, 'files' => 1]);
});

it('removes the files it wrote when the receipt cannot be saved', function () {
    $client = OverdueClient::add($this->fakes->cartera);
    $this->app->instance(AuditLogger::class, new class implements AuditLogger
    {
        public function record(string $action, ?string $subjectType = null, int|string|null $subjectId = null, array $changes = [], array $context = []): void
        {
            throw new RuntimeException('The audit log is down.');
        }
    });

    expect(fn () => app(UploadReceipt::class)->handle(newReceipt($client->id), SoportesUsers::advisor()))
        ->toThrow(RuntimeException::class, 'The audit log is down.');

    expect(Receipt::query()->count())->toBe(0)
        ->and(ReceiptFile::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a file whose content is not an accepted type, whatever its name', function () {
    $client = OverdueClient::add($this->fakes->cartera);
    $file = UploadedFile::fake()->createWithContent('factura.pdf', "MZ\x90\x00 not a pdf")->mimeType('application/x-dosexec');

    expect(fn () => app(UploadReceipt::class)->handle(newReceipt($client->id, files: [$file]), SoportesUsers::advisor()))
        ->toThrow(fn (ReceiptRefused $e) => expect($e->field)->toBe('archivos')->and($e->reason)->toBe(ReceiptRefused::FILE_NOT_ACCEPTED));

    expect(Storage::disk('local')->allFiles())->toBe([]);
});
