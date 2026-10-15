<?php

namespace Tests\Modules\Core\Support;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\IngestaSink;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;
use Tests\Fakes\FakeIngestaSink;

/**
 * Sends robot batches through the in-memory IngestaSink, so the real Core
 * listeners of BatchAccepted import them, and builds rows with every column
 * of etl/esquema.py.
 */
final class RobotBatches
{
    private function __construct(
        public readonly FakeIngestaSink $sink,
    ) {}

    /**
     * Binds a fresh FakeIngestaSink as the IngestaSink of the application.
     */
    public static function install(Application $app): self
    {
        $app->instance(IngestaSink::class, $sink = new FakeIngestaSink);

        return new self($sink);
    }

    /**
     * Sends a cartera batch of $company cut on $cutoff ("Y-m-d").
     *
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function receivables(CompanyCode $company, string $cutoff, array $rows): LoadRecord
    {
        return $this->send(Dataset::Receivables, $company, $cutoff, $rows);
    }

    /**
     * Sends a batch of any dataset with a new batch id.
     *
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function send(Dataset $dataset, CompanyCode $company, string $cutoff, array $rows, LoadSource $source = LoadSource::Robot): LoadRecord
    {
        return $this->sink->receive(new IncomingBatch(
            dataset: $dataset,
            company: $company,
            cutoffDate: CarbonImmutable::parse($cutoff),
            source: $source,
            batchId: (string) Str::uuid(),
            rows: $rows,
        ));
    }

    /**
     * A cartera row: an invoice of 1,190,000 pesos of Ferretería El Tornillo,
     * advisor 12, issued on 2026-09-01 and due on 2026-10-01, owed in full.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function receivable(array $overrides = []): array
    {
        return array_replace([
            'slp_code' => 12,
            'email_asesor' => 'carlos.perez@segurex.com',
            'codigo_sn' => 'C900123456',
            'nombre_sn' => 'Ferretería El Tornillo S.A.S.',
            'nit' => '900123456-8',
            'grupo' => 'Ferreterías',
            'municipio' => 'Medellín',
            'cuenta_visible' => '13050505',
            'tipo_doc' => '13',
            'documento' => '104233',
            'documento_base' => '104233',
            'referencia_1' => '104233',
            'no_referencia' => null,
            'asesor' => 'Carlos Pérez',
            'total_plazos' => 0,
            'no_plazo' => 0,
            'condicion_pago' => '30 días',
            'fecha_contabilizacion' => '2026-09-01',
            'fecha_vencimiento' => '2026-10-01',
            'fecha_documento' => '2026-09-01',
            'dias' => 14,
            'limite_credito' => '50000000.00',
            'importe_original' => '1190000.00',
            'base_gravable' => '1000000.00',
            'saldo_base' => '1000000.00',
            'saldo_vencido' => '1190000.00',
            'valor_abonado' => '0.00',
            'sin_vencer' => '0.00',
            'valor_cheques' => '0.00',
            'd_0_30' => '1190000.00',
            'd_31_60' => '0.00',
            'd_61_90' => '0.00',
            'd_91_120' => '0.00',
            'd_121_150' => '0.00',
            'd_151_180' => '0.00',
            'd_mas_180' => '0.00',
            'saldo' => '1190000.00',
            'rango' => '0-30',
        ], $overrides);
    }

    /**
     * An asesores row: advisor 12, Carlos Pérez, active in SAP.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function advisor(array $overrides = []): array
    {
        return array_replace([
            'slp_code' => 12,
            'nombre' => 'Carlos Pérez',
            'email' => 'carlos.perez@segurex.com',
            'activo' => 'Y',
            'clientes' => 40,
        ], $overrides);
    }
}
