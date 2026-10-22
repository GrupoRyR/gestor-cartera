<?php

use App\Modules\Bancos\Application\Import\StatementImport;
use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Bancos\Support\Fixtures;

const BANCOS_INGEST_TOKEN = 'token-de-escritura-de-prueba';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    config()->set('bancos.ingest_token', BANCOS_INGEST_TOKEN);
    config()->set('bancos.api_token', 'token-de-lectura-de-prueba');
    RateLimiter::clear('bancos-api');
});

/**
 * A valid body; fields can be overridden.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function bancosPayload(array $fields = []): array
{
    return array_merge([
        'empresa' => 'SGX',
        'banco' => 'bancolombia',
        'referencia_lote' => 'extracto-15-oct',
        'movimientos' => [
            ['fecha' => '2026-10-15', 'valor' => '1250000.00', 'descripcion' => 'TRANSFERENCIA ACH', 'referencia' => 'ACH1', 'cuenta' => '123-456'],
            ['fecha' => '2026-10-15', 'tipo' => 'credito', 'valor' => '480500.50', 'referencia' => 'ACH2'],
            ['fecha' => '2026-10-15', 'tipo' => 'debito', 'valor' => '12000', 'descripcion' => 'COMISION'],
        ],
    ], $fields);
}

function bancosPost(array $body, ?string $token = BANCOS_INGEST_TOKEN): TestResponse
{
    return test()->postJson('/api/movimientos', $body, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

it('is served at POST /api/movimientos', function () {
    expect(route('bancos.api.movimientos.store', absolute: false))->toBe('/api/movimientos');
});

describe('authentication', function () {
    it('refuses a request without a token', function () {
        bancosPost(bancosPayload(), null)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');

        expect(BankMovement::query()->count())->toBe(0);
    });

    it('refuses a wrong token', function () {
        bancosPost(bancosPayload(), 'otro-token')->assertUnauthorized();
    });

    it('refuses the read token: a leaked read token cannot write', function () {
        bancosPost(bancosPayload(), 'token-de-lectura-de-prueba')->assertUnauthorized();

        expect(BankMovement::query()->count())->toBe(0);
    });

    it('is closed while no token is configured, even for an empty one', function (mixed $configured) {
        config()->set('bancos.ingest_token', $configured);

        bancosPost(bancosPayload(), 'cualquiera')->assertUnauthorized();
        bancosPost(bancosPayload(), '')->assertUnauthorized();
        $this->postJson('/api/movimientos', bancosPayload())->assertUnauthorized();
    })->with([null, '']);

    it('does not accept the token in the query string', function () {
        $this->postJson('/api/movimientos?token='.BANCOS_INGEST_TOKEN, bancosPayload())->assertUnauthorized();
    });

    it('limits how fast tokens can be guessed', function () {
        foreach (range(1, 60) as $attempt) {
            bancosPost(bancosPayload(), 'mal-'.$attempt)->assertUnauthorized();
        }

        bancosPost(bancosPayload(), BANCOS_INGEST_TOKEN)->assertStatus(429);
    });
});

describe('storing', function () {
    it('keeps the credits and counts the debit it skipped', function () {
        bancosPost(bancosPayload())
            ->assertCreated()
            ->assertJson(['nuevos' => 2, 'repetidos' => 0, 'debitos_omitidos' => 1, 'errores' => []]);

        $first = BankMovement::query()->orderBy('id')->first();

        expect(BankMovement::query()->count())->toBe(2)
            ->and($first?->amount)->toBe('1250000.00')
            ->and($first?->description)->toBe('TRANSFERENCIA ACH')
            ->and($first?->reference)->toBe('ACH1')
            ->and($first?->account)->toBe('123-456')
            ->and($first?->company)->toBe(CompanyCode::SGX)
            ->and($first?->bank)->toBe(Bank::Bancolombia);
    });

    it('records the load as coming from the API, with no user', function () {
        $id = bancosPost(bancosPayload())->json('carga');

        $load = BankLoad::query()->findOrFail($id);

        expect($load->source)->toBe(ImportSource::Api)
            ->and($load->file_name)->toBe('extracto-15-oct')
            ->and($load->user_id)->toBeNull();

        $this->fakes->audit->assertRecorded('bancos.carga.imported');
    });

    it('is safe to send twice: the second answer says nothing was new', function () {
        bancosPost(bancosPayload())->assertCreated();

        bancosPost(bancosPayload())
            ->assertOk()
            ->assertJson(['nuevos' => 0, 'repetidos' => 2]);

        expect(BankMovement::query()->count())->toBe(2);
    });

    it('does not duplicate what a file already loaded: files and the API share the importer', function () {
        $statement = new UploadedFile(Fixtures::path(Bank::Bancolombia), 'extracto.csv', 'text/csv', null, true);

        app(StatementImport::class)
            ->fromFile($statement->getRealPath(), Bank::Bancolombia, CompanyCode::SGX, ImportSource::Folder);

        // The first credit of the invented Bancolombia statement, as the API would send it.
        bancosPost(bancosPayload(['movimientos' => [
            ['fecha' => '2026-10-13', 'valor' => '2225395.00', 'descripcion' => 'TRANSFERENCIA CTA SUC VIRTUAL', 'cuenta' => '00000000000'],
        ]]))->assertOk()->assertJson(['nuevos' => 0, 'repetidos' => 1]);
    });

    it('uses the currency of the account unless the movement says another', function () {
        bancosPost(bancosPayload([
            'banco' => 'miami',
            'movimientos' => [
                ['fecha' => '2026-10-15', 'valor' => '100.00', 'referencia' => 'A'],
                ['fecha' => '2026-10-15', 'valor' => '200.00', 'referencia' => 'B', 'moneda' => 'cop'],
            ],
        ]))->assertCreated();

        expect(BankMovement::query()->orderBy('id')->pluck('currency')->all())->toBe(['USD', 'COP']);
    });

    it('accepts amounts as JSON numbers', function () {
        bancosPost(bancosPayload(['movimientos' => [['fecha' => '2026-10-15', 'valor' => 1250000.5]]]))->assertCreated();

        expect(BankMovement::query()->sole()->amount)->toBe('1250000.50');
    });

    it('reports credits dated in the future instead of storing them', function () {
        bancosPost(bancosPayload(['movimientos' => [
            ['fecha' => '2026-10-15', 'valor' => '1000', 'referencia' => 'A'],
            ['fecha' => '2026-12-03', 'valor' => '2000', 'referencia' => 'B'],
        ]]))
            ->assertCreated()
            ->assertJson(['nuevos' => 1])
            ->assertJsonPath('errores.0.mensaje', fn (string $message) => str_contains($message, '03/12/2026'));
    });
});

describe('validation', function () {
    it('names the movement and the field that are wrong', function () {
        bancosPost(bancosPayload(['movimientos' => [
            ['fecha' => '2026-10-15', 'valor' => '1000'],
            ['fecha' => '15/10/2026', 'valor' => '1.000,50'],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['movimientos.1.fecha', 'movimientos.1.valor']);

        expect(BankMovement::query()->count())->toBe(0);
    });

    it('requires the company, the bank and at least one movement', function () {
        bancosPost([])->assertUnprocessable()->assertJsonValidationErrors(['empresa', 'banco', 'movimientos']);
        bancosPost(bancosPayload(['movimientos' => []]))->assertUnprocessable()->assertJsonValidationErrors(['movimientos']);
    });

    it('rejects an unknown company or bank', function () {
        bancosPost(bancosPayload(['empresa' => 'XYZ', 'banco' => 'bbva']))->assertUnprocessable()->assertJsonValidationErrors(['empresa', 'banco']);
    });

    it('rejects amounts that are zero, negative or written with thousands separators', function (mixed $value) {
        bancosPost(bancosPayload(['movimientos' => [['fecha' => '2026-10-15', 'valor' => $value]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['movimientos.0.valor']);
    })->with(['0', '0.00', '-100', '1,000.00', '1.000,00', 'cien', '12.345']);

    it('rejects a direction that is neither credit nor debit', function () {
        bancosPost(bancosPayload(['movimientos' => [['fecha' => '2026-10-15', 'valor' => '10', 'tipo' => 'abono']]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['movimientos.0.tipo']);
    });

    it('limits the size of a request', function () {
        $movements = array_fill(0, 5001, ['fecha' => '2026-10-15', 'valor' => '10']);

        bancosPost(bancosPayload(['movimientos' => $movements]))->assertUnprocessable()->assertJsonValidationErrors(['movimientos']);
    });
});
