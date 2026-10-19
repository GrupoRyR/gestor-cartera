<?php

use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;

const BANCOS_READ_TOKEN = 'token-de-lectura-de-prueba';

beforeEach(function () {
    config()->set('bancos.api_token', BANCOS_READ_TOKEN);
    config()->set('bancos.ingest_token', 'token-de-escritura-de-prueba');
    RateLimiter::clear('bancos-api');
});

function bancosGet(string $query, ?string $token = BANCOS_READ_TOKEN): TestResponse
{
    return test()->getJson('/api/recaudo?'.$query, $token === null ? [] : ['Authorization' => 'Bearer '.$token]);
}

it('is served at GET /api/recaudo, the URL Treasury expects', function () {
    expect(route('bancos.api.recaudo', absolute: false))->toBe('/api/recaudo');
});

describe('authentication', function () {
    it('refuses a request without a token', function () {
        bancosGet('desde=2026-10-01&hasta=2026-10-31', null)->assertUnauthorized();
    });

    it('refuses the write token', function () {
        bancosGet('desde=2026-10-01&hasta=2026-10-31', 'token-de-escritura-de-prueba')->assertUnauthorized();
    });

    it('is closed while no token is configured', function (mixed $configured) {
        config()->set('bancos.api_token', $configured);

        bancosGet('desde=2026-10-01&hasta=2026-10-31', 'cualquiera')->assertUnauthorized();
        bancosGet('desde=2026-10-01&hasta=2026-10-31', '')->assertUnauthorized();
    })->with([null, '']);

    it('is read-only', function () {
        $this->postJson('/api/recaudo', [], ['Authorization' => 'Bearer '.BANCOS_READ_TOKEN])->assertStatus(405);
    });
});

describe('the answer', function () {
    it('gives the daily and weekly collections of the range', function () {
        BankMovement::factory()->on('2026-10-05')->inBank(Bank::Bogota)->worth('1000000.00')->create();
        BankMovement::factory()->on('2026-10-05')->inBank(Bank::Bancolombia)->worth('250000.50')->create();
        BankMovement::factory()->on('2026-10-12')->inBank(Bank::Bogota)->worth('7.00')->create();

        bancosGet('desde=2026-10-01&hasta=2026-10-31')
            ->assertOk()
            ->assertExactJson([
                'desde' => '2026-10-01',
                'hasta' => '2026-10-31',
                'empresa' => null,
                'moneda' => 'COP',
                'diario' => [
                    ['fecha' => '2026-10-05', 'empresa' => 'SGX', 'total' => 1_250_000.5, 'por_banco' => ['bogota' => 1_000_000.0, 'bancolombia' => 250_000.5], 'movimientos' => 2],
                    ['fecha' => '2026-10-12', 'empresa' => 'SGX', 'total' => 7.0, 'por_banco' => ['bogota' => 7.0], 'movimientos' => 1],
                ],
                'semanal' => [
                    ['semana_inicio' => '2026-10-05', 'semana_fin' => '2026-10-11', 'empresa' => 'SGX', 'total' => 1_250_000.5, 'por_banco' => ['bogota' => 1_000_000.0, 'bancolombia' => 250_000.5]],
                    ['semana_inicio' => '2026-10-12', 'semana_fin' => '2026-10-18', 'empresa' => 'SGX', 'total' => 7.0, 'por_banco' => ['bogota' => 7.0]],
                ],
            ]);
    });

    it('filters by company', function () {
        BankMovement::factory()->forCompany(CompanyCode::SGX)->on('2026-10-05')->worth('1.00')->create();
        BankMovement::factory()->forCompany(CompanyCode::SDC)->on('2026-10-05')->worth('2.00')->create();

        bancosGet('desde=2026-10-05&hasta=2026-10-05&empresa=SDC')
            ->assertOk()
            ->assertJsonPath('empresa', 'SDC')
            ->assertJsonCount(1, 'diario')
            ->assertJsonPath('diario.0.total', 2);
    });

    it('answers an empty list, not an error, when there are no credits', function () {
        bancosGet('desde=2026-10-01&hasta=2026-10-31')->assertOk()->assertJson(['diario' => [], 'semanal' => []]);
    });

    it('answers a single day when both dates are the same', function () {
        BankMovement::factory()->on('2026-10-05')->worth('3.00')->create();

        bancosGet('desde=2026-10-05&hasta=2026-10-05')->assertOk()->assertJsonCount(1, 'diario');
    });

    it('serializes the banks of a day as an object even when empty of banks that day', function () {
        BankMovement::factory()->on('2026-10-05')->worth('3.00')->create();

        expect(bancosGet('desde=2026-10-05&hasta=2026-10-05')->getContent())->toContain('"por_banco":{"bancolombia":3}');
    });
});

describe('validation', function () {
    it('requires both dates', function () {
        bancosGet('')->assertUnprocessable()->assertJsonValidationErrors(['desde', 'hasta']);
    });

    it('rejects dates that are not AAAA-MM-DD', function () {
        bancosGet('desde=01/10/2026&hasta=2026-10-31')->assertUnprocessable()->assertJsonValidationErrors(['desde']);
    });

    it('rejects a range that ends before it starts', function () {
        bancosGet('desde=2026-10-31&hasta=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['hasta']);
    });

    it('rejects a range longer than a year', function () {
        bancosGet('desde=2025-01-01&hasta=2026-10-31')->assertUnprocessable()->assertJsonValidationErrors(['hasta']);

        bancosGet('desde=2026-01-01&hasta=2026-12-31')->assertOk();
    });

    it('rejects an unknown company', function () {
        bancosGet('desde=2026-10-01&hasta=2026-10-31&empresa=XYZ')->assertUnprocessable()->assertJsonValidationErrors(['empresa']);
    });
});
