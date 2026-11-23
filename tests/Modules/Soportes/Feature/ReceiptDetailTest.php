<?php

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Modules\Soportes\Infrastructure\Models\ReceiptFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\Make;
use Tests\Modules\Soportes\Support\SoportesUsers;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    Storage::fake('local');
    $this->advisor = SoportesUsers::advisor();
    $this->client = soportesDetailClient(10);
});

/**
 * A client of advisor $advisorCode, known to the portfolio fake.
 */
function soportesDetailClient(int $advisorCode, CompanyCode $company = CompanyCode::SGX): ClientSummary
{
    $summary = Make::client(company: $company, name: 'Ferretería El Tornillo SAS', advisor: Make::advisor(company: $company, code: $advisorCode));
    test()->fakes->cartera->addClient(Make::clientDetail($summary));

    return $summary;
}

/**
 * A receipt of $client uploaded by the test advisor, covering FV-1001, with
 * one stored PDF named comprobante.pdf.
 *
 * @param  array<string, mixed>  $attributes
 */
function soportesDetailReceipt(ClientSummary $client, ?User $uploader = null, array $attributes = []): Receipt
{
    $receipt = Receipt::factory()->forClient($client)->uploadedBy($uploader ?? test()->advisor)->create([
        'amount' => '1200000.00',
        'paid_at' => '2026-11-18',
        'note' => 'Pagó por transferencia desde Bancolombia',
        ...$attributes,
    ]);

    $receipt->invoices()->create([
        'document_number' => 'FV-1001',
        'document_type' => 'Factura',
        'due_at' => '2026-10-06',
        'balance' => '1200000.00',
        'days_overdue' => 45,
    ]);

    soportesDetailFile($receipt, 'comprobante.pdf', "%PDF-1.4 comprobante {$receipt->id}");

    return $receipt;
}

function soportesDetailFile(Receipt $receipt, string $name, string $bytes, string $mime = 'application/pdf'): ReceiptFile
{
    $path = "soportes/SGX/2026/11/{$receipt->id}-".count($receipt->files()->get()).'.pdf';
    Storage::disk('local')->put($path, $bytes);

    return $receipt->files()->create(['path' => $path, 'original_name' => $name, 'mime' => $mime, 'size' => strlen($bytes)]);
}

/**
 * The download URL of $file of $receipt.
 */
function soportesDownloadUrl(Receipt $receipt, ReceiptFile $file): string
{
    return route('soportes.files.download', ['soporte' => $receipt, 'archivo' => $file->id], false);
}

it('serves the detail and the downloads under /soportes', function () {
    $receipt = soportesDetailReceipt($this->client);

    expect(route('soportes.show', $receipt, false))->toBe('/soportes/'.$receipt->id)
        ->and(soportesDownloadUrl($receipt, $receipt->files[0]))->toBe("/soportes/{$receipt->id}/archivos/{$receipt->files[0]->id}");
});

describe('the detail', function () {
    it('shows the advisor the receipt of their client, its invoices and its files', function () {
        $receipt = soportesDetailReceipt($this->client, SoportesUsers::analyst());
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs($this->advisor)->get('/soportes/'.$receipt->id)
            ->assertOk()
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertSee($this->client->code)
            ->assertSee('Pagó por transferencia desde Bancolombia')
            ->assertSee('Transferencia')
            ->assertSee('Pendiente')
            ->assertSee('María Paula Rojas')
            ->assertSee('FV-1001')
            ->assertSee('comprobante.pdf')
            ->assertSee(soportesDownloadUrl($receipt, $receipt->files[0]));
    });

    it('shows who applied it and the cash receipt number', function () {
        $reviewer = SoportesUsers::create(Role::PortfolioManager, 'Gloria Inés Mora');
        $receipt = soportesDetailReceipt($this->client, attributes: [
            'status' => 'aplicado', 'receipt_number' => 'RC-55012', 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(),
        ]);

        $this->actingAs(SoportesUsers::analyst())->get('/soportes/'.$receipt->id)
            ->assertOk()
            ->assertSee('Aplicado')
            ->assertSee('RC-55012')
            ->assertSee('Gloria Inés Mora');
    });

    it('shows the advisor why it was rejected', function () {
        $receipt = soportesDetailReceipt($this->client, attributes: [
            'status' => 'rechazado', 'rejection_reason' => 'El valor no coincide con el extracto.', 'reviewed_by' => SoportesUsers::analyst()->id, 'reviewed_at' => now(),
        ]);
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs($this->advisor)->get('/soportes/'.$receipt->id)
            ->assertOk()
            ->assertSee('Rechazado')
            ->assertSee('El valor no coincide con el extracto.');
    });

    it('is linked from the inbox', function () {
        $receipt = soportesDetailReceipt($this->client);

        $this->actingAs(SoportesUsers::analyst())->get('/soportes')->assertSee(route('soportes.show', $receipt));
    });

    it('lets the superadmin read it', function () {
        $receipt = soportesDetailReceipt($this->client);

        $this->actingAs(SoportesUsers::create(Role::SuperAdmin))->get('/soportes/'.$receipt->id)->assertOk();
    });

    it('keeps out another advisor, a reviewer of another company and whoever has no access', function () {
        $receipt = soportesDetailReceipt($this->client);
        $this->fakes->cartera->restrictToAdvisor(20);

        $this->actingAs(SoportesUsers::advisor('Asesor B'))->get('/soportes/'.$receipt->id)->assertForbidden();
        $this->actingAs(SoportesUsers::create(Role::Treasury))->get('/soportes/'.$receipt->id)->assertForbidden();

        app()->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SDC, [CompanyCode::SDC]));

        $this->actingAs(SoportesUsers::analyst())->get('/soportes/'.$receipt->id)->assertForbidden();
    });

    it('answers 404 for a receipt that does not exist', function () {
        $this->actingAs(SoportesUsers::analyst())->get('/soportes/999999')->assertNotFound();
    });
});

describe('downloading a file', function () {
    it('streams the bytes as an attachment with the uploaded name and the sniffed type', function () {
        $receipt = soportesDetailReceipt($this->client);
        $file = soportesDetailFile($receipt, 'pago noviembre.png', 'png-bytes', 'image/png');
        $this->fakes->cartera->restrictToAdvisor(10);

        $response = $this->actingAs($this->advisor)->get(soportesDownloadUrl($receipt, $file))->assertOk();

        expect($response->streamedContent())->toBe('png-bytes')
            ->and($response->headers->get('Content-Type'))->toBe('image/png')
            ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;')
            ->and($response->headers->get('Content-Disposition'))->toContain('pago noviembre.png');
    });

    it('lets a reviewer of the company download it', function () {
        $receipt = soportesDetailReceipt($this->client);

        $response = $this->actingAs(SoportesUsers::analyst())->get(soportesDownloadUrl($receipt, $receipt->files[0]))->assertOk();

        expect($response->streamedContent())->toBe("%PDF-1.4 comprobante {$receipt->id}");
    });

    it('answers 404 for a file of another receipt', function () {
        $receipt = soportesDetailReceipt($this->client);
        $other = soportesDetailReceipt($this->client);

        $this->actingAs(SoportesUsers::analyst())->get(soportesDownloadUrl($receipt, $other->files[0]))->assertNotFound();
    });

    it('keeps out another advisor and a reviewer of another company', function () {
        $receipt = soportesDetailReceipt($this->client);
        $url = soportesDownloadUrl($receipt, $receipt->files[0]);
        $this->fakes->cartera->restrictToAdvisor(20);

        $this->actingAs(SoportesUsers::advisor('Asesor B'))->get($url)->assertForbidden();

        app()->instance(CurrentCompany::class, new FakeCurrentCompany(CompanyCode::SDC, [CompanyCode::SDC]));

        $this->actingAs(SoportesUsers::analyst())->get($url)->assertForbidden();
    });

    it('answers 404, and logs it, when the file is missing on the disk', function () {
        $receipt = soportesDetailReceipt($this->client);
        Storage::disk('local')->delete($receipt->files[0]->path);
        Log::spy();

        $this->actingAs(SoportesUsers::analyst())->get(soportesDownloadUrl($receipt, $receipt->files[0]))->assertNotFound();

        Log::shouldHaveReceived('warning')->once();
    });
});
