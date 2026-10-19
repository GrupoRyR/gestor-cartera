<?php

namespace Tests\Modules\Asesores\Support;

use App\Models\User;
use App\Modules\Asesores\Database\Seeders\AsesoresPermissionSeeder;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Database\Seeders\CorePermissionSeeder;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/**
 * The people and portfolios the Asesores screens are tested against, saved
 * with Core's own records, so every test exercises the real portfolio scope
 * of CarteraRepository and the real CompromisoService, not a stand-in.
 *
 * The cast: Ana is the SGX advisor 12 and Bruno the SGX advisor 20; Gloria is
 * a sales manager whose team is SGX-30 (Felipe) and SDC-40 (Juliana) and who
 * is also linked to SDC-50; María Paula is the portfolio analyst. SGX-12 in
 * SDC (Hernán) belongs to nobody. Today is 2026-10-16 and every company's
 * latest snapshot is cut on 2026-10-15.
 *
 * Latest snapshot, by client (balance, days overdue):
 *
 *   SGX-12 Agro Arango        5.000.000 (100), 2.000.000 (-10)
 *   SGX-12 Ferretería Franco  1.500.000 (-3)
 *   SGX-20 Bodega Bedoya      3.000.000 (40)
 *   SGX-30 Comercial Castaño  1.000.000 (10)
 *   (none) Distribuciones D.  500.000 (5)
 *   SDC-12 Equipos Echeverri  800.000 (20)
 *   SDC-40 Jardines Jaramillo 600.000 (0)
 *   SDC-50 Granos Giraldo     2.500.000 (70)
 */
final class AdvisorWorld
{
    public const string TODAY = '2026-10-16 10:00';

    public const string CUTOFF = '2026-10-15';

    public User $ana;

    public User $bruno;

    public User $gloria;

    public User $mariaPaula;

    public PortfolioLoadRecord $sgx;

    public PortfolioLoadRecord $sdc;

    /**
     * @var array<string, ClientRecord> The clients by short key: agro, ferreteria, bodega, comercial, distribuciones, equipos, jardines, granos.
     */
    public array $clients = [];

    private function __construct() {}

    /**
     * Seeds the permissions, saves the cast, the advisors and the portfolio.
     */
    public static function create(): self
    {
        (new CorePermissionSeeder)->run();
        (new AsesoresPermissionSeeder)->run();

        $world = new self;
        $world->people();
        $world->portfolio();

        return $world;
    }

    /**
     * A client of $company in the latest snapshot, with the figures Core
     * caches on the client derived from its invoices.
     *
     * @param  list<array{0: string, 1: int}>  $invoices  Balance ("1234.56") and days overdue of each open invoice.
     * @param  array<string, mixed>  $attributes  Overrides of the saved client.
     */
    public function client(string $name, CompanyCode $company, ?int $advisorCode, array $invoices, array $attributes = []): ClientRecord
    {
        $balance = 0.0;
        $overdue = 0.0;
        $oldest = null;

        foreach ($invoices as [$amount, $days]) {
            $balance += (float) $amount;
            $overdue += $days > 0 ? (float) $amount : 0.0;
            $oldest = $oldest === null ? $days : max($oldest, $days);
        }

        $client = CarteraRecords::client($name, [
            'company' => $company,
            'advisor_code' => $advisorCode,
            'status' => $overdue > 0 ? ClientStatus::Overdue : ClientStatus::Current,
            'balance' => number_format($balance, 2, '.', ''),
            'overdue' => number_format($overdue, 2, '.', ''),
            'max_days_overdue' => $oldest ?? 0,
            ...$attributes,
        ]);

        $snapshot = $company === CompanyCode::SGX ? $this->sgx : $this->sdc;

        foreach ($invoices as [$amount, $days]) {
            CarteraRecords::invoice($snapshot, $client, $amount, $days);
        }

        return $client;
    }

    private function people(): void
    {
        $this->ana = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
        $this->bruno = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX);
        $this->gloria = CoreUsers::create(Role::SalesManager, CompanyCode::SGX, CompanyCode::SDC);
        $this->mariaPaula = CoreUsers::create(Role::PortfolioAnalyst, CompanyCode::SGX, CompanyCode::SDC);

        $this->ana->update(['name' => 'Ana Álvarez']);
        $this->bruno->update(['name' => 'Bruno Bedoya']);
        $this->gloria->update(['name' => 'Gloria Gómez']);
        $this->mariaPaula->update(['name' => 'María Paula Ríos']);

        TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $this->ana);
        TeamUsers::advisor(CompanyCode::SGX, 20, 'Diana Duque', $this->bruno);
        $felipe = TeamUsers::advisor(CompanyCode::SGX, 30, 'Felipe Ospina');
        TeamUsers::advisor(CompanyCode::SDC, 12, 'Hernán Toro');
        $juliana = TeamUsers::advisor(CompanyCode::SDC, 40, 'Juliana Restrepo');
        TeamUsers::advisor(CompanyCode::SDC, 50, 'Laura Vélez', $this->gloria);

        AdvisorRecord::teamOf($this->gloria)->attach([$felipe->id, $juliana->id]);
    }

    private function portfolio(): void
    {
        // Older cuts first: the history of Agro Arango (Ana) and Bodega Bedoya (Bruno).
        $oct1 = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-01');
        $oct8 = CarteraRecords::snapshot(CompanyCode::SGX, '2026-10-08');

        $this->sgx = CarteraRecords::snapshot(CompanyCode::SGX, self::CUTOFF);
        $this->sdc = CarteraRecords::snapshot(CompanyCode::SDC, self::CUTOFF);

        $this->clients['agro'] = $this->client('Agro Arango', CompanyCode::SGX, 12, [['5000000.00', 100], ['2000000.00', -10]], [
            'last_activity_at' => '2026-10-14 09:30:00',
        ]);
        $this->clients['ferreteria'] = $this->client('Ferretería Franco', CompanyCode::SGX, 12, [['1500000.00', -3]], ['status' => ClientStatus::DueSoon]);
        $this->clients['bodega'] = $this->client('Bodega Bedoya', CompanyCode::SGX, 20, [['3000000.00', 40]]);
        $this->clients['comercial'] = $this->client('Comercial Castaño', CompanyCode::SGX, 30, [['1000000.00', 10]]);
        $this->clients['distribuciones'] = $this->client('Distribuciones Duque', CompanyCode::SGX, null, [['500000.00', 5]]);
        $this->clients['equipos'] = $this->client('Equipos Echeverri', CompanyCode::SDC, 12, [['800000.00', 20]]);
        $this->clients['jardines'] = $this->client('Jardines Jaramillo', CompanyCode::SDC, 40, [['600000.00', 0]]);
        $this->clients['granos'] = $this->client('Granos Giraldo', CompanyCode::SDC, 50, [['2500000.00', 70]]);

        CarteraRecords::invoice($oct1, $this->clients['agro'], '4000000.00', 86);
        CarteraRecords::invoice($oct8, $this->clients['agro'], '4500000.00', 93);
        CarteraRecords::invoice($oct1, $this->clients['bodega'], '2500000.00', 26);
    }
}
