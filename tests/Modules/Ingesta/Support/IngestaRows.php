<?php

namespace Tests\Modules\Ingesta\Support;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
use Carbon\CarbonImmutable;

/**
 * Fictitious, schema-valid rows and batches for the Ingesta tests.
 */
final class IngestaRows
{
    /**
     * A cartera row (all 38 columns) with the given overrides.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function cartera(array $overrides = []): array
    {
        $row = array_fill_keys(DatasetSchemas::for(Dataset::Receivables)->columnNames(), null);

        return array_merge($row, [
            'slp_code' => 11,
            'email_asesor' => 'ana@segurex.com',
            'codigo_sn' => 'C001',
            'nombre_sn' => 'Ferretería Ñandú',
            'documento' => 'FV-1001',
            'no_plazo' => 1,
            'fecha_vencimiento' => '2026-10-01',
            'dias' => 14,
            'importe_original' => '1500000.00',
            'saldo' => '1000000.50',
            'rango' => '0-30',
        ], $overrides);
    }

    /**
     * An asesores row with the given overrides.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function advisor(int $code = 11, array $overrides = []): array
    {
        return array_merge([
            'slp_code' => $code,
            'nombre' => "Asesor {$code}",
            'email' => "asesor{$code}@segurex.com",
            'activo' => 'Y',
            'clientes' => 40,
        ], $overrides);
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows
     */
    public static function batch(
        string $batchId,
        array $rows,
        Dataset $dataset = Dataset::Advisors,
        CompanyCode $company = CompanyCode::SGX,
        string $cutoffDate = '2026-10-15',
        LoadSource $source = LoadSource::Robot,
        ?int $receivedBy = null,
    ): IncomingBatch {
        return new IncomingBatch(
            $dataset,
            $company,
            CarbonImmutable::parse($cutoffDate, 'America/Bogota')->startOfDay(),
            $source,
            $batchId,
            $rows,
            $receivedBy,
        );
    }
}
