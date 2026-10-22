<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Data\PortfolioTotals;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroPortfolio;

/*
 * The circularization waits for the cartera of the month close: on the 1st
 * the latest cutoff of each company must be the last day of the month
 * before; otherwise that company's run is not started (its slot stays free
 * for a manual run once the close is loaded), unless --con-corte-actual.
 */

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->portfolio = CobroPortfolio::seed($this->fakes);
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-31', 'America/Bogota');
});

/**
 * Gives each company its own latest cutoff (Y-m-d, or null for no
 * snapshot): the cartera fake has a single one for every company.
 *
 * @param  array<string, string|null>  $cutoffs  CompanyCode value => cutoff.
 */
function cobroHoldCutoffs(array $cutoffs): void
{
    $inner = test()->fakes->cartera;

    app()->instance(CarteraRepository::class, new class($inner, $cutoffs) implements CarteraRepository
    {
        /**
         * @param  array<string, string|null>  $cutoffs
         */
        public function __construct(private readonly CarteraRepository $inner, private readonly array $cutoffs) {}

        public function asSystem(): CarteraRepository
        {
            return new self($this->inner->asSystem(), $this->cutoffs);
        }

        public function paginateClients(ClientQuery $query, int $perPage = 25): LengthAwarePaginator
        {
            return $this->inner->paginateClients($query, $perPage);
        }

        public function allClients(ClientQuery $query): iterable
        {
            return $this->inner->allClients($query);
        }

        public function client(int $clientId): ?ClientDetail
        {
            return $this->inner->client($clientId);
        }

        public function openInvoices(int $clientId): array
        {
            return $this->inner->openInvoices($clientId);
        }

        public function totals(?ClientQuery $query = null): PortfolioTotals
        {
            $totals = $this->inner->totals($query);
            $companies = $query?->companies ?? [];

            if (count($companies) !== 1 || ! array_key_exists($companies[0]->value, $this->cutoffs)) {
                return $totals;
            }

            $cutoff = $this->cutoffs[$companies[0]->value];

            return new PortfolioTotals(
                $cutoff === null ? null : CarbonImmutable::parse($cutoff, 'America/Bogota'),
                $totals->total,
                $totals->overdue,
                $totals->clientCount,
                $totals->overdueClientCount,
                $totals->aging,
            );
        }

        public function history(CarbonImmutable $from, CarbonImmutable $to, ?ClientQuery $query = null): array
        {
            return $this->inner->history($from, $to, $query);
        }

        public function advisors(): array
        {
            return $this->inner->advisors();
        }
    });
}

function cobroHoldMessage(string $company, string $code, string $cutoff): string
{
    return "La circularización de {$company} no se envió: la cartera cargada es del {$cutoff} y el cierre del mes es el 31/10/2026. "
        ."Cuando cargues el cierre, ejecuta php artisan cobro:circularizacion --empresa={$code}. "
        .'Si ya no hay una carga del cierre, agrega --con-corte-actual para enviarla con el último corte.';
}

it('sends when the loaded cartera is the month close', function () {
    $this->artisan('cobro:circularizacion')->assertSuccessful();

    expect(Run::query()->count())->toBe(2)
        ->and($this->fakes->mail->sent())->toHaveCount(3);
});

it('holds every company back when the cartera is a day before the close', function () {
    Log::spy();
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-30', 'America/Bogota');

    $this->artisan('cobro:circularizacion')
        ->expectsOutputToContain(cobroHoldMessage('Segurex Latam', 'SGX', '30/10/2026'))
        ->expectsOutputToContain(cobroHoldMessage('Soluciones y Derivados', 'SDC', '30/10/2026'))
        ->assertFailed();

    expect(Run::query()->count())->toBe(0);
    $this->fakes->mail->assertNothingSent();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_starts_with($message, 'Circularization held')
        && $context === ['company' => 'SGX', 'cutoff' => '2026-10-30', 'expected_close' => '2026-10-31'])->once();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['company' => 'SDC', 'cutoff' => '2026-10-30', 'expected_close' => '2026-10-31'])->once();
});

it('holds a company without a loaded cartera', function () {
    Log::spy();
    cobroHoldCutoffs(['SGX' => null]);

    $this->artisan('cobro:circularizacion --empresa=SGX')
        ->expectsOutputToContain('La circularización de Segurex Latam no se envió: no hay cartera cargada y el cierre del mes es el 31/10/2026. Cuando cargues el cierre, ejecuta php artisan cobro:circularizacion --empresa=SGX.')
        ->assertFailed();

    expect(Run::query()->count())->toBe(0);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context === ['company' => 'SGX', 'cutoff' => null, 'expected_close' => '2026-10-31'])->once();
});

it('runs only the company whose cartera is the close', function () {
    cobroHoldCutoffs(['SGX' => '2026-10-31', 'SDC' => '2026-10-30']);

    $this->artisan('cobro:circularizacion')
        ->expectsOutputToContain(cobroHoldMessage('Soluciones y Derivados', 'SDC', '30/10/2026'))
        ->expectsOutputToContain('Segurex Latam: Circularización en cola para 2 clientes; 1 sin correo y 1 excluidos.')
        ->assertSuccessful();

    expect(Run::query()->pluck('company')->all())->toBe([CompanyCode::SGX])
        ->and(collect($this->fakes->mail->sent())->every(fn (OutgoingMail $mail): bool => $mail->company === CompanyCode::SGX))->toBeTrue();
});

it('sends with the latest cutoff when asked to, and says that cutoff', function () {
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-30', 'America/Bogota');

    $this->artisan('cobro:circularizacion --con-corte-actual')->assertSuccessful();

    expect(Run::query()->count())->toBe(2)
        ->and(collect($this->fakes->mail->sent())->pluck('subject')->unique()->values()->all())
        ->toEqualCanonicalizing(['Circularización de Cartera – Corte al 30 de octubre de 2026', 'Circularización de Cartera – Corte al 30 de octubre del 2026']);
});

it('sends in the same monthly slot once the close is loaded later', function () {
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-30', 'America/Bogota');
    $this->artisan('cobro:circularizacion')->assertFailed();

    $this->travelTo(CarbonImmutable::parse('2026-11-03 10:00:00', 'America/Bogota'));
    $this->fakes->cartera->cutoffDate = CarbonImmutable::parse('2026-10-31', 'America/Bogota');

    $this->artisan('cobro:circularizacion --empresa=SGX')->assertSuccessful();

    $run = Run::query()->sole();

    expect($run->company)->toBe(CompanyCode::SGX)
        ->and($run->scheduled_for->equalTo(CarbonImmutable::parse('2026-11-01 09:00', 'America/Bogota')))->toBeTrue()
        ->and(collect($this->fakes->mail->sent())->pluck('subject')->unique()->values()->all())
        ->toBe(['Circularización de Cartera – Corte al 31 de octubre de 2026']);
});

it('checks only the companies of --empresa', function () {
    cobroHoldCutoffs(['SGX' => '2026-10-30', 'SDC' => '2026-10-31']);

    $this->artisan('cobro:circularizacion --empresa=SDC')
        ->doesntExpectOutputToContain('Segurex Latam')
        ->assertSuccessful();

    expect(Run::query()->pluck('company')->all())->toBe([CompanyCode::SDC]);
});

it('never lets a held company through when it is the one asked for', function () {
    cobroHoldCutoffs(['SGX' => '2026-10-31', 'SDC' => '2026-10-30']);

    $this->artisan('cobro:circularizacion --empresa=SDC')->assertFailed();

    expect(Run::query()->count())->toBe(0);
});

it('still rejects a company that does not have the circularization', function () {
    $this->artisan('cobro:circularizacion --empresa=XYZ')
        ->expectsOutputToContain('La empresa XYZ no tiene este envío. Empresas válidas: SGX, SDC.')
        ->assertFailed();

    expect(Run::query()->count())->toBe(0);
});
