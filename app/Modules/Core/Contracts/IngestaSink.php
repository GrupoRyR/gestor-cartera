<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\Exceptions\InvalidBatch;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use InvalidArgumentException;

/**
 * Receives the batches of the ETL robot (and manual uploads) and keeps the
 * load history.
 *
 * Implemented by Ingesta (phase 1); tests use Tests\Fakes\FakeIngestaSink.
 * Not user-scoped: callers authorize who may see the history.
 *
 * Rows: one associative array per row, keyed by the snake_case column names
 * of etl/esquema.py (the "pg" names), in the order the robot sent them:
 * - cartera (Dataset::Receivables), one row per invoice installment:
 *   slp_code, email_asesor, codigo_sn, nombre_sn, nit, grupo, municipio,
 *   cuenta_visible, tipo_doc, documento, documento_base, referencia_1,
 *   no_referencia, asesor, total_plazos, no_plazo, condicion_pago,
 *   fecha_contabilizacion, fecha_vencimiento, fecha_documento, dias,
 *   limite_credito, importe_original, base_gravable, saldo_base,
 *   saldo_vencido, valor_abonado, sin_vencer, valor_cheques, d_0_30,
 *   d_31_60, d_61_90, d_91_120, d_121_150, d_151_180, d_mas_180, saldo,
 *   rango;
 * - asesores (Dataset::Advisors): slp_code, nombre, email, activo, clientes;
 * - contactos, ventas, recaudos, cruces, maestro_clientes and
 *   maestro_vendedores: the columns Ingesta adds to etl/esquema.py together
 *   with their queries, following the same rules.
 * Values: integer columns are int; numeric columns are decimal strings with
 * a dot and no thousands separator (e.g. "1234567.89"), so consumers keep
 * exact amounts; dates are "Y-m-d" strings; text columns are strings; a
 * missing value is null.
 *
 * Consumers never call receive(): they listen to BatchAccepted and read
 * rows(). Core consumes cartera, asesores and contactos; Comisiones consumes
 * ventas, recaudos, cruces, maestro_clientes and maestro_vendedores.
 */
interface IngestaSink
{
    /**
     * Validates the batch, stores it as a dated snapshot (history is never
     * overwritten), records an Accepted load and dispatches
     * {@see BatchAccepted} once.
     *
     * Idempotent by IncomingBatch::$batchId: a repeated batch returns the
     * original accepted record and dispatches nothing. A rejected batch is
     * recorded in the history as LoadStatus::Rejected with its error, nothing
     * else is stored, and its batchId may be sent again.
     *
     * @throws InvalidBatch When validation fails.
     */
    public function receive(IncomingBatch $batch): LoadRecord;

    /**
     * Rows of a load in the order they were received, lazily; empty for a
     * rejected load.
     *
     * @return iterable<int, array<string, scalar|null>>
     *
     * @throws RecordNotFound When there is no load with that id.
     */
    public function rows(int $loadId): iterable;

    /**
     * The current snapshot of a dataset: the Accepted load with the latest
     * cutoff date (the latest received wins a tie); null when there is none.
     */
    public function latest(Dataset $dataset, CompanyCode $company): ?LoadRecord;

    /**
     * Loads, accepted and rejected, newest received first; null filters
     * match everything.
     *
     * @param  int  $limit  At least 1.
     * @return list<LoadRecord>
     *
     * @throws InvalidArgumentException When $limit is below 1.
     */
    public function history(?Dataset $dataset = null, ?CompanyCode $company = null, int $limit = 30): array;
}
