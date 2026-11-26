<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Modules\Soportes\Infrastructure\Models\ReceiptFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Soportes\Support\OverdueClient;
use Tests\Modules\Soportes\Support\SoportesUsers;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    Storage::fake('local');
    $this->client = OverdueClient::add($this->fakes->cartera, advisorCode: 10);
});

/**
 * Posts the upload form for the overdue client of the test; the fields can
 * be overridden.
 *
 * @param  array<string, mixed>  $fields
 */
function soportesUpload(User $user, array $fields = []): TestResponse
{
    return test()->actingAs($user)->post('/soportes', array_merge([
        'cliente' => test()->client->id,
        'facturas' => ['FV-1001', 'FV-1002'],
        'valor' => '2000000',
        'fecha_pago' => '2026-11-19',
        'metodo' => 'pse',
        'nota' => 'Pagó las dos facturas',
        'archivos' => [UploadedFile::fake()->create('comprobante.pdf', 200, 'application/pdf')],
    ], $fields));
}

/**
 * A real uploaded file named $name whose bytes are $content, so its type is
 * sniffed from the content (the fake files report the type of their name).
 */
function soportesRealFile(string $name, string $content): UploadedFile
{
    $path = (string) tempnam(sys_get_temp_dir(), 'soporte');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'application/pdf', null, true);
}

it('serves the upload under /soportes', function () {
    expect(route('soportes.create', absolute: false))->toBe('/soportes/nuevo')
        ->and(route('soportes.store', absolute: false))->toBe('/soportes');
});

describe('access', function () {
    it('sends a guest to the login', function () {
        $this->get('/soportes/nuevo')->assertRedirect('/login');
        $this->post('/soportes')->assertRedirect('/login');
    });

    it('keeps out whoever may not upload, the superadmin included', function (?Role $role) {
        $user = SoportesUsers::create($role);

        $this->actingAs($user)->get('/soportes/nuevo')->assertForbidden();
        soportesUpload($user)->assertForbidden();

        expect(Receipt::query()->count())->toBe(0);
    })->with([[Role::SuperAdmin], [Role::Treasury], [Role::Admin], [null]]);
});

describe('step 1: picking the client', function () {
    it('lists every client of the advisor, overdue or not, linking to the form', function () {
        $this->fakes->cartera->restrictToAdvisor(10);
        OverdueClient::add($this->fakes->cartera, advisorCode: 20, name: 'Cliente De Otra Asesora SAS');
        $this->fakes->cartera->addClient(Make::clientDetail(Make::client(name: 'Cliente Al Día SAS', advisor: Make::advisor(code: 10))));

        $this->actingAs(SoportesUsers::advisor())->get('/soportes/nuevo')
            ->assertOk()
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertSee('/soportes/nuevo?cliente='.$this->client->id, false)
            ->assertSee('Cliente Al Día SAS')
            ->assertDontSee('Cliente De Otra Asesora SAS')
            ->assertSee('Saldo')
            ->assertDontSee('Saldo vencido');
    });

    it('searches the clients by name', function () {
        OverdueClient::add($this->fakes->cartera, name: 'Distribuciones La Rueda SAS');

        $this->actingAs(SoportesUsers::analyst())->get('/soportes/nuevo?buscar=rueda')
            ->assertOk()
            ->assertSee('Distribuciones La Rueda SAS')
            ->assertDontSee('Ferretería El Tornillo SAS');
    });
});

describe('step 2: the form', function () {
    it('tells when the client has no open invoice', function () {
        $client = Make::client(name: 'Cliente Sin Saldo SAS', balance: 0.0, advisor: Make::advisor(code: 10));
        $this->fakes->cartera->addClient(Make::clientDetail($client));

        $this->actingAs(SoportesUsers::analyst())->get('/soportes/nuevo?cliente='.$client->id)
            ->assertOk()
            ->assertSee('Este cliente no tiene facturas abiertas')
            ->assertDontSee('name="archivos[]"', false);
    });

    it('shows the advisor\'s own client with its open invoices to pick, due or not', function () {
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs(SoportesUsers::advisor())->get('/soportes/nuevo?cliente='.$this->client->id)
            ->assertOk()
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertSee('value="FV-1001"', false)
            ->assertSee('value="FV-1002"', false)
            ->assertSee('value="FV-1003"', false)
            ->assertSee('Al día')
            ->assertDontSee('NC-1001')
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee('name="archivos[]"', false)
            ->assertSee('En pesos. Usa coma para los centavos.');
    });

    it('takes the amount in a money input with cents, sent back clean', function () {
        $html = $this->actingAs(SoportesUsers::analyst())->get('/soportes/nuevo?cliente='.$this->client->id)->assertOk()->getContent() ?: '';

        $field = Html::parse($html)->labelledControl('Valor pagado');

        expect($field->getAttribute('name'))->toBe('valor')
            ->and($field->getAttribute('type'))->toBe('text')
            ->and($field->hasAttribute('data-money-input'))->toBeTrue()
            ->and($field->getAttribute('data-decimals'))->toBe('2')
            ->and($field->getAttribute('inputmode'))->toBe('decimal')
            ->and($field->hasAttribute('required'))->toBeTrue();
    });

    it('answers 404 for a client of another advisor', function () {
        $this->fakes->cartera->restrictToAdvisor(20);

        $this->actingAs(SoportesUsers::advisor())->get('/soportes/nuevo?cliente='.$this->client->id)->assertNotFound();
    });

    it('answers 404 for a client of a company out of the analyst\'s scope', function () {
        $client = OverdueClient::add($this->fakes->cartera, company: CompanyCode::SDC, name: 'Cliente Derivados SAS');

        $this->actingAs(SoportesUsers::analyst())->get('/soportes/nuevo?cliente='.$client->id)->assertNotFound();
    });
});

describe('uploading', function () {
    it('saves the advisor\'s receipt with its files and opens it, saying it awaits review', function () {
        $this->fakes->cartera->restrictToAdvisor(10);
        $advisor = SoportesUsers::advisor();

        $response = soportesUpload($advisor)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Soporte cargado. Queda pendiente de revisión.');

        $receipt = Receipt::query()->with(['invoices', 'files'])->sole();

        $response->assertRedirect('/soportes/'.$receipt->id);

        expect($receipt->uploaded_by)->toBe($advisor->id)
            ->and($receipt->amount)->toBe('2000000.00')
            ->and($receipt->method?->value)->toBe('pse')
            ->and($receipt->invoices->pluck('document_number')->all())->toBe(['FV-1001', 'FV-1002'])
            ->and($receipt->files)->toHaveCount(1);

        Storage::disk('local')->assertExists($receipt->files[0]->path);
    });

    it('lets the analyst upload for any client of their company', function () {
        $response = soportesUpload(SoportesUsers::analyst(), ['metodo' => '', 'nota' => ''])->assertSessionHasNoErrors();
        $receipt = Receipt::query()->sole();

        $response->assertRedirect(route('soportes.show', $receipt));

        expect($receipt->method)->toBeNull();
    });

    it('accepts up to three files, PDF, JPG or PNG', function () {
        soportesUpload(SoportesUsers::analyst(), ['archivos' => [
            UploadedFile::fake()->create('uno.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('dos.jpg'),
            UploadedFile::fake()->image('tres.png'),
        ]])->assertSessionHasNoErrors();

        expect(ReceiptFile::query()->pluck('mime')->sort()->values()->all())->toBe(['application/pdf', 'image/jpeg', 'image/png']);
    });

    it('accepts a real PDF, sniffed from its bytes', function () {
        soportesUpload(SoportesUsers::analyst(), ['archivos' => [soportesRealFile('comprobante.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n")]])
            ->assertSessionHasNoErrors();

        expect(ReceiptFile::query()->sole()->mime)->toBe('application/pdf');
    });

    it('refuses a client of another advisor', function () {
        $this->fakes->cartera->restrictToAdvisor(20);

        soportesUpload(SoportesUsers::advisor())
            ->assertRedirect('/soportes/nuevo?cliente='.$this->client->id)
            ->assertSessionHasErrors('cliente');

        expect(Receipt::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('shows the errors on the form of the client, with what was typed', function () {
        $this->followingRedirects();

        soportesUpload(SoportesUsers::analyst(), [
            'facturas' => [],
            'valor' => '1500.555',
            'archivos' => [UploadedFile::fake()->create('carta.docx', 10)],
        ])
            ->assertOk()
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertSee('Marca al menos una factura.')
            ->assertSee('Escribe el valor en pesos, sin puntos de miles y con máximo dos decimales.')
            ->assertSee('Cada comprobante debe ser un PDF, JPG o PNG.')
            ->assertSee('value="1500.555"', false);
    });

    it('refuses the form and keeps the input when a field is wrong', function (Closure $fields, string $error) {
        soportesUpload(SoportesUsers::analyst(), $fields())
            ->assertRedirect('/soportes/nuevo?cliente='.$this->client->id)
            ->assertSessionHasErrors($error)
            ->assertSessionHasInput('fecha_pago');

        expect(Receipt::query()->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    })->with([
        'no invoices' => [fn () => ['facturas' => []], 'facturas'],
        'a credit note' => [fn () => ['facturas' => ['FV-1001', 'NC-1001']], 'facturas'],
        'the same invoice twice' => [fn () => ['facturas' => ['FV-1001', 'FV-1001']], 'facturas.0'],
        'a payment date in the future' => [fn () => ['fecha_pago' => '2026-11-21'], 'fecha_pago'],
        'an amount of zero' => [fn () => ['valor' => '0'], 'valor'],
        'an amount with three decimals' => [fn () => ['valor' => '1500.555'], 'valor'],
        'an amount in Colombian notation' => [fn () => ['valor' => '1.500.000'], 'valor'],
        'an unknown method' => [fn () => ['metodo' => 'efectivo'], 'metodo'],
        'no files' => [fn () => ['archivos' => []], 'archivos'],
        'four files' => [fn () => ['archivos' => array_map(
            fn (int $n) => UploadedFile::fake()->create("soporte-{$n}.pdf", 10, 'application/pdf'),
            [1, 2, 3, 4],
        )], 'archivos'],
        'a file over 10 MB' => [fn () => ['archivos' => [UploadedFile::fake()->create('grande.pdf', 10241, 'application/pdf')]], 'archivos.0'],
        'a Word document' => [fn () => ['archivos' => [UploadedFile::fake()->create('carta.docx', 10)]], 'archivos.0'],
        'a program renamed as a PDF' => [fn () => ['archivos' => [soportesRealFile('factura.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00".str_repeat("\x00", 64))]], 'archivos.0'],
        'a text file named as a PDF' => [fn () => ['archivos' => [soportesRealFile('factura.pdf', "Esto no es un PDF.\n")]], 'archivos.0'],
    ]);
});
