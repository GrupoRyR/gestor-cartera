<?php

namespace App\Modules\Ingesta\Database\Seeders;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Ingesta\Application\BatchValidator;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Fictitious load history for local development: the robot's receivables
 * snapshots of both companies (with the totals of the Core demo snapshots),
 * the weekly advisors, the Segurex commission datasets of the last days, a
 * manual upload and two rejected loads with their row errors, the last one a
 * sales batch the Comisiones load board points at.
 *
 * Only the load records are written: they carry no row payload, and nothing
 * is announced (no BatchAccepted), so no module imports them again.
 * DatabaseSeeder runs it only with APP_ENV=local; it does nothing when there
 * are loads already.
 */
final class IngestaDemoSeeder extends Seeder
{
    private const string TIMEZONE = 'America/Bogota';

    /** Days of daily commission loads. */
    private const int DAYS = 5;

    /** @var list<Dataset> */
    private const array COMMISSION_DATASETS = [
        Dataset::SalesRepMaster, Dataset::CustomerMaster, Dataset::Sales, Dataset::Collections, Dataset::Offsets,
    ];

    public function run(CarteraRepository $cartera): void
    {
        if (IngestaLoad::query()->exists()) {
            return;
        }

        $today = CarbonImmutable::today(self::TIMEZONE);
        $cartera = $cartera->asSystem();

        foreach (CompanyCode::cases() as $company) {
            $query = new ClientQuery(companies: [$company]);

            foreach ($cartera->history($today->subYear(), $today, $query) as $n => $totals) {
                $cutoff = $totals->cutoffDate ?? $today;

                $this->accepted(Dataset::Receivables, $company, $cutoff, 20 + $totals->clientCount * 2, [
                    'total' => sprintf('%.2f', $totals->total),
                    'overdue' => sprintf('%.2f', $totals->overdue),
                    // One snapshot of the year was uploaded by hand.
                    'source' => $n === 3 ? LoadSource::Manual : LoadSource::Robot,
                ]);
            }

            for ($week = 3; $week >= 0; $week--) {
                $this->accepted(Dataset::Advisors, $company, $today->subWeeks($week)->startOfWeek(), 5);
            }
        }

        for ($back = self::DAYS; $back >= 1; $back--) {
            foreach (self::COMMISSION_DATASETS as $n => $dataset) {
                $this->accepted($dataset, CompanyCode::SGX, $today->subDays($back), 12 + $n * 9 + $back);
            }
        }

        $this->rejected(Dataset::Receivables, CompanyCode::SDC, $today->subDays(3), [
            'cutoff_date' => ['La fecha de corte no puede ser posterior a hoy.'],
        ]);

        $this->rejected(Dataset::Sales, CompanyCode::SGX, $today, [
            'rows.3.total' => ['El total debe ser un número con dos decimales.'],
            'rows.8.fecha_documento' => ['La fecha del documento no es válida.'],
            'rows.8.slp_code' => ['El código del vendedor es obligatorio.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function accepted(Dataset $dataset, CompanyCode $company, CarbonImmutable $cutoff, int $rows, array $attributes = []): void
    {
        $batchId = self::batchId($dataset, $company, $cutoff);

        IngestaLoad::query()->create([
            'batch_id' => $batchId,
            'accepted_batch_id' => $batchId,
            'dataset' => $dataset,
            'company' => $company,
            'cutoff_date' => $cutoff->toDateString(),
            'source' => LoadSource::Robot,
            'status' => LoadStatus::Accepted,
            'row_count' => $rows,
            'total' => null,
            'overdue' => null,
            'columns' => DatasetSchemas::for($dataset)->columnNames(),
            'error' => null,
            'errors' => null,
            // The robot sends each cutoff the next morning, never in the future.
            'received_at' => $cutoff->addDay()->setTime(6, 2 + $rows % 50)->min(CarbonImmutable::now()),
            'received_by' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function rejected(Dataset $dataset, CompanyCode $company, CarbonImmutable $cutoff, array $errors): void
    {
        IngestaLoad::query()->create([
            'batch_id' => self::batchId($dataset, $company, $cutoff).'-r',
            'accepted_batch_id' => null,
            'dataset' => $dataset,
            'company' => $company,
            'cutoff_date' => $cutoff->toDateString(),
            'source' => LoadSource::Robot,
            'status' => LoadStatus::Rejected,
            'row_count' => 40,
            'error' => BatchValidator::summarize($errors),
            'errors' => $errors,
            'received_at' => $cutoff->setTime(6, 40)->min(CarbonImmutable::now()),
            'received_by' => null,
        ]);
    }

    private static function batchId(Dataset $dataset, CompanyCode $company, CarbonImmutable $cutoff): string
    {
        return sprintf('demo-%s-%s-%s-%s', $dataset->value, Str::lower($company->value), $cutoff->format('Ymd'), Str::lower(Str::random(6)));
    }
}
