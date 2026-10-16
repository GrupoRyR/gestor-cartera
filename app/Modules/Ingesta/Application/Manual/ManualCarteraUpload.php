<?php

namespace App\Modules\Ingesta\Application\Manual;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Ingesta\Application\BatchValidator;
use App\Modules\Ingesta\Domain\Manual\CellConverter;
use App\Modules\Ingesta\Domain\Manual\ReceivablesAging;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use App\Modules\Ingesta\Infrastructure\Manual\CarteraWorkbookReader;
use Carbon\CarbonImmutable;

/**
 * The manual fallback of the robot: turns the SAP "Cartera del día" Excel
 * into the cartera batch the robot would have sent, for a preview and,
 * once confirmed, for IngestaSink::receive().
 *
 * Each row gets the 38 cartera columns: the ones in the Excel, converted
 * to the contract formats; "dias", the aging buckets, "rango" and
 * "saldo_vencido" recomputed from the cutoff like etl/queries/cartera.sql;
 * "valor_cheques" and "valor_abonado" as 0.00 and the other missing ones as
 * null; and the advisor code filled by AdvisorCodes.
 */
final class ManualCarteraUpload
{
    /** Columns the query computes; the Excel copies are recomputed. */
    private const array DERIVED = ['dias', 'saldo_vencido', 'sin_vencer', 'd_0_30', 'd_31_60', 'd_61_90', 'd_91_120', 'd_121_150', 'd_151_180', 'd_mas_180', 'rango'];

    /** Amounts the query always sends as zero. */
    private const array ZERO_WHEN_MISSING = ['valor_cheques', 'valor_abonado'];

    public function __construct(
        private readonly CarteraWorkbookReader $reader,
        private readonly AdvisorCodes $advisors,
        private readonly BatchValidator $validator,
    ) {}

    /**
     * @throws UnreadableWorkbook When the file is not a cartera workbook.
     */
    public function prepare(string $path, CompanyCode $company, CarbonImmutable $cutoff, ?int $userId): ManualPreview
    {
        $sheet = $this->reader->read($path);
        $cutoffDate = $cutoff->format('Y-m-d');

        $rows = array_map(fn (array $row): array => $this->row($row['values'], $cutoffDate), $sheet->rows);

        $batch = new IncomingBatch(
            dataset: Dataset::Receivables,
            company: $company,
            cutoffDate: $cutoff,
            source: LoadSource::Manual,
            batchId: self::batchId($company, $cutoff, (string) hash_file('sha256', $path)),
            rows: $this->advisors->fill($rows, $company),
            receivedBy: $userId,
        );

        return new ManualPreview(
            $batch,
            array_column($sheet->rows, 'line'),
            $sheet->ignoredHeaders,
            $this->validator->errors($batch),
        );
    }

    /**
     * The idempotency key: the same file for the same company and cutoff
     * is the same load.
     */
    public static function batchId(CompanyCode $company, CarbonImmutable $cutoff, string $sha256): string
    {
        return sprintf('manual-cartera-%s-%s-%s', $company->value, $cutoff->format('Y-m-d'), substr($sha256, 0, 16));
    }

    /**
     * @param  array<string, mixed>  $values  Raw cells by column name.
     * @return array<string, scalar|null>
     */
    private function row(array $values, string $cutoffDate): array
    {
        $row = [];

        foreach (DatasetSchemas::for(Dataset::Receivables)->columns as $column) {
            $value = in_array($column->name, self::DERIVED, true) ? null : CellConverter::convert($values[$column->name] ?? null, $column->type);

            $row[$column->name] = $value === null && in_array($column->name, self::ZERO_WHEN_MISSING, true) ? '0.00' : $value;
        }

        $days = ReceivablesAging::days($row['fecha_vencimiento'], $cutoffDate);

        /** @var array<string, scalar|null> */
        return array_merge($row, ReceivablesAging::columns($row['saldo'], $days));
    }
}
