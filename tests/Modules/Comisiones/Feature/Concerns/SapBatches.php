<?php

namespace Tests\Modules\Comisiones\Feature\Concerns;

use App\Modules\Core\Contracts\Data\IncomingBatch;
use App\Modules\Core\Contracts\Data\LoadRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Fakes\FakeIngestaSink;

/**
 * Sends Comisiones batches through the in-memory IngestaSink, so the module's
 * BatchAccepted listener stages them, and builds rows with every column of
 * the datasets (app/Modules/Ingesta/Domain/Schema/DatasetSchemas.php).
 */
final class SapBatches
{
    public function __construct(
        public readonly FakeIngestaSink $sink,
    ) {}

    /**
     * Sends a batch with a new batch id.
     *
     * @param  list<array<string, scalar|null>>  $rows
     */
    public function send(Dataset $dataset, array $rows, string $cutoff = '2026-07-31', CompanyCode $company = CompanyCode::SGX): LoadRecord
    {
        return $this->sink->receive(new IncomingBatch(
            dataset: $dataset,
            company: $company,
            cutoffDate: CarbonImmutable::parse($cutoff),
            source: LoadSource::Robot,
            batchId: (string) Str::uuid(),
            rows: $rows,
        ));
    }

    /**
     * A sales line: invoice 8001 line 0 of code 14, 1,000,000 pesos on 2026-07-15.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function sale(array $overrides = []): array
    {
        return array_replace([
            'tipo_objeto' => 13,
            'doc_entry' => 5001,
            'numero_documento' => 8001,
            'linea' => 0,
            'clase_documento' => 'factura',
            'estado_documento' => 'abierto',
            'fecha_documento' => '2026-07-15',
            'fecha_contabilizacion' => '2026-07-15',
            'slp_code' => 14,
            'vendedor' => 'MONICA RIVERA AREVALO',
            'canal' => 'Distribución',
            'codigo_cliente' => 'C900123456',
            'cliente' => 'Ferretería El Tornillo SAS',
            'nit' => '900123456',
            'ciudad' => 'Medellín',
            'pedido' => null,
            'id_pedido_app' => null,
            'oc_cliente' => null,
            'ciudad_envio' => null,
            'direccion_envio' => null,
            'marca' => null,
            'familia' => null,
            'codigo_articulo' => 'A-1',
            'descripcion' => 'Tornillo',
            'cantidad' => '10.000000',
            'costo_unitario' => null,
            'costo_total' => null,
            'precio_lista' => null,
            'precio_unitario' => null,
            'total' => '1000000.00',
            'iva' => '190000.00',
            'moneda' => '$',
            'trm' => null,
            'comentarios' => null,
        ], $overrides);
    }

    /**
     * A payment line: payment 7001 line 0 of code 14 applying 500,000 pesos
     * (420,168.07 without VAT) on 2026-07-20 to invoice 8001 of 2026-06-30.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function payment(array $overrides = []): array
    {
        return array_replace([
            'doc_entry_pago' => 7001,
            'numero_pago' => 3001,
            'linea' => 0,
            'fecha_pago' => '2026-07-20',
            'estado_pago' => 'vigente',
            'tipo_documento' => 13,
            'doc_entry_factura' => 5001,
            'numero_factura' => 8001,
            'fecha_factura' => '2026-06-30',
            'fecha_vencimiento' => '2026-07-30',
            'codigo_cliente' => 'C900123456',
            'nombre_cliente' => 'Ferretería El Tornillo SAS',
            'slp_code' => 14,
            'vendedor' => 'MONICA RIVERA AREVALO',
            'valor_factura' => '1190000.00',
            'base_gravable' => '1000000.00',
            'iva_factura' => '190000.00',
            'descuento_factura' => null,
            'moneda' => 'COP',
            'trm' => '1.000000',
            'valor_aplicado' => '500000.00',
            'valor_pagado_sin_iva' => '420168.07',
            'comision_sap' => null,
        ], $overrides);
    }

    /**
     * A crossing line: crossing 9001 line 0, an advance of 1,190,000 pesos on
     * 2026-07-22 over invoice 8001 of client C900123456.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function crossing(array $overrides = []): array
    {
        return array_replace([
            'numero_cruce' => 9001,
            'linea' => 0,
            'fecha_cruce' => '2026-07-22',
            'tipo_cruce' => 'anticipo',
            'tipo_conciliacion' => 0,
            'estado' => 'vigente',
            'nit' => '900123456',
            'codigo_cliente' => 'C900123456',
            'codigo_proveedor' => null,
            'razon_social' => 'Ferretería El Tornillo SAS',
            'doc_entry_factura' => 5001,
            'numero_factura' => 8001,
            'fecha_factura' => '2026-06-30',
            'slp_code_factura' => 14,
            'vendedor_factura' => 'MONICA RIVERA AREVALO',
            'valor_factura' => '1190000.00',
            'base_gravable' => '1000000.00',
            'iva_factura' => '190000.00',
            'valor_compensado' => '1190000.00',
            'valor_compensado_sin_iva' => '1000000.00',
            'usuario' => 'manager',
        ], $overrides);
    }

    /**
     * A client of the master: C900123456, assigned to code 14.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function client(array $overrides = []): array
    {
        return array_replace([
            'codigo_cliente' => 'C900123456',
            'nit' => '900123456',
            'razon_social' => 'Ferretería El Tornillo SAS',
            'slp_code' => 14,
            'vendedor' => 'MONICA RIVERA AREVALO',
            'canal' => 'Distribución',
            'ciudad' => 'Medellín',
            'codigo_proveedor' => null,
            'estado' => 'activo',
            'fecha_modificacion' => null,
        ], $overrides);
    }

    /**
     * A seller of the master: code 14.
     *
     * @param  array<string, scalar|null>  $overrides
     * @return array<string, scalar|null>
     */
    public static function seller(array $overrides = []): array
    {
        return array_replace([
            'slp_code' => 14,
            'nombre' => 'MONICA RIVERA AREVALO',
            'estado' => 'activo',
            'correo' => 'monica.rivera@segurex.com',
            'canal' => 'Distribución',
            'zona' => 'Antioquia',
            'fecha_modificacion' => '2026-01-10',
        ], $overrides);
    }
}
