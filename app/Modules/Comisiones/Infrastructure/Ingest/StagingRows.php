<?php

namespace App\Modules\Comisiones\Infrastructure\Ingest;

use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;

/**
 * Maps the rows of the Ingesta datasets (columns of
 * app/Modules/Ingesta/Domain/Schema/DatasetSchemas.php) to the columns of
 * the staging tables. Integers arrive as int, decimals as dot strings and
 * dates as "Y-m-d"; they are stored as they come. Blank text is null.
 */
final class StagingRows
{
    /**
     * A ventas row for comisiones_ventas, keyed "{tipo_objeto}-{doc_entry}-{linea}".
     *
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    public static function sale(array $row, int $loadId): array
    {
        return [
            'clave' => sprintf('%d-%d-%d', (int) $row['tipo_objeto'], (int) $row['doc_entry'], (int) $row['linea']),
            'load_id' => $loadId,
            'tipo_objeto' => (int) $row['tipo_objeto'],
            'doc_entry' => (int) $row['doc_entry'],
            'numero_documento' => (int) $row['numero_documento'],
            'linea' => (int) $row['linea'],
            'clase_documento' => self::required($row, 'clase_documento'),
            'estado_documento' => self::required($row, 'estado_documento'),
            'fecha_documento' => self::required($row, 'fecha_documento'),
            'fecha_contabilizacion' => self::text($row, 'fecha_contabilizacion'),
            'slp_code' => (int) $row['slp_code'],
            'vendedor' => self::text($row, 'vendedor'),
            'canal' => self::text($row, 'canal'),
            'codigo_cliente' => self::required($row, 'codigo_cliente'),
            'cliente' => self::text($row, 'cliente'),
            'nit' => self::text($row, 'nit'),
            'ciudad' => self::text($row, 'ciudad'),
            'codigo_articulo' => self::text($row, 'codigo_articulo'),
            'descripcion' => self::text($row, 'descripcion'),
            'total' => self::required($row, 'total'),
            'iva' => self::text($row, 'iva'),
            'moneda' => self::raw($row, 'moneda'),
            'trm' => self::text($row, 'trm'),
        ];
    }

    /**
     * A recaudos row for comisiones_recaudos, keyed "{doc_entry_pago}-{linea}".
     *
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    public static function payment(array $row, int $loadId): array
    {
        return [
            'clave' => sprintf('%d-%d', (int) $row['doc_entry_pago'], (int) $row['linea']),
            'load_id' => $loadId,
            'doc_entry_pago' => (int) $row['doc_entry_pago'],
            'numero_pago' => (int) $row['numero_pago'],
            'linea' => (int) $row['linea'],
            'fecha_pago' => self::required($row, 'fecha_pago'),
            'estado_pago' => self::required($row, 'estado_pago'),
            'tipo_documento' => (int) $row['tipo_documento'],
            'doc_entry_factura' => (int) $row['doc_entry_factura'],
            'numero_factura' => self::integer($row, 'numero_factura'),
            'fecha_factura' => self::text($row, 'fecha_factura'),
            'fecha_vencimiento' => self::text($row, 'fecha_vencimiento'),
            'codigo_cliente' => self::required($row, 'codigo_cliente'),
            'nombre_cliente' => self::text($row, 'nombre_cliente'),
            'slp_code' => (int) $row['slp_code'],
            'vendedor' => self::text($row, 'vendedor'),
            'valor_factura' => self::text($row, 'valor_factura'),
            'base_gravable' => self::text($row, 'base_gravable'),
            'iva_factura' => self::text($row, 'iva_factura'),
            'descuento_factura' => self::text($row, 'descuento_factura'),
            'moneda' => self::raw($row, 'moneda'),
            'trm' => self::text($row, 'trm'),
            'valor_aplicado' => self::required($row, 'valor_aplicado'),
            'valor_pagado_sin_iva' => self::required($row, 'valor_pagado_sin_iva'),
            'comision_sap' => self::text($row, 'comision_sap'),
        ];
    }

    /**
     * A cruces row for comisiones_cruces, keyed "sap-{numero_cruce}-{linea}".
     * SAP crossings need no review: they are stored as approved.
     *
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    public static function crossing(array $row, int $loadId): array
    {
        return [
            'clave' => sprintf('sap-%d-%d', (int) $row['numero_cruce'], (int) $row['linea']),
            'origen' => CrossingRecord::SAP,
            'load_id' => $loadId,
            'numero_cruce' => (int) $row['numero_cruce'],
            'linea' => (int) $row['linea'],
            'fecha_cruce' => self::required($row, 'fecha_cruce'),
            'tipo_cruce' => self::required($row, 'tipo_cruce'),
            'tipo_conciliacion' => self::integer($row, 'tipo_conciliacion'),
            'estado_cruce' => self::required($row, 'estado'),
            'nit' => self::text($row, 'nit'),
            'codigo_cliente' => self::required($row, 'codigo_cliente'),
            'codigo_proveedor' => self::text($row, 'codigo_proveedor'),
            'razon_social' => self::text($row, 'razon_social'),
            'doc_entry_factura' => self::integer($row, 'doc_entry_factura'),
            'numero_factura' => self::text($row, 'numero_factura'),
            'fecha_factura' => self::text($row, 'fecha_factura'),
            'slp_code_factura' => self::integer($row, 'slp_code_factura'),
            'vendedor_factura' => self::text($row, 'vendedor_factura'),
            'valor_factura' => self::text($row, 'valor_factura'),
            'base_gravable' => self::text($row, 'base_gravable'),
            'iva_factura' => self::text($row, 'iva_factura'),
            'valor_compensado' => self::required($row, 'valor_compensado'),
            'valor_compensado_sin_iva' => self::text($row, 'valor_compensado_sin_iva'),
            'usuario' => self::text($row, 'usuario'),
            'estado' => CaptureStatus::Approved->value,
        ];
    }

    /**
     * A maestro_clientes row for comisiones_clientes, keyed by codigo_cliente.
     *
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    public static function client(array $row, int $loadId): array
    {
        return [
            'codigo_cliente' => self::required($row, 'codigo_cliente'),
            'nit' => self::text($row, 'nit'),
            'razon_social' => self::text($row, 'razon_social'),
            'slp_code' => (int) $row['slp_code'],
            'vendedor' => self::text($row, 'vendedor'),
            'canal' => self::text($row, 'canal'),
            'ciudad' => self::text($row, 'ciudad'),
            'codigo_proveedor' => self::text($row, 'codigo_proveedor'),
            'estado' => self::required($row, 'estado'),
            'fecha_modificacion' => self::text($row, 'fecha_modificacion'),
            'load_id' => $loadId,
        ];
    }

    /**
     * A maestro_vendedores row for comisiones_vendedores, keyed by slp_code.
     *
     * @param  array<string, scalar|null>  $row
     * @return array<string, scalar|null>
     */
    public static function seller(array $row, int $loadId): array
    {
        return [
            'slp_code' => (int) $row['slp_code'],
            'nombre' => self::required($row, 'nombre'),
            'estado' => self::required($row, 'estado'),
            'correo' => self::text($row, 'correo'),
            'canal' => self::text($row, 'canal'),
            'zona' => self::text($row, 'zona'),
            'fecha_modificacion' => self::text($row, 'fecha_modificacion'),
            'load_id' => $loadId,
        ];
    }

    /**
     * A trimmed text value; blank or missing is null.
     *
     * @param  array<string, scalar|null>  $row
     */
    private static function text(array $row, string $column): ?string
    {
        $value = trim((string) ($row[$column] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * The value as SAP sent it, only null when missing (the currency "$" or " "
     * is mapped later, when the line is read).
     *
     * @param  array<string, scalar|null>  $row
     */
    private static function raw(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * A required text value; Ingesta has already checked it is there.
     *
     * @param  array<string, scalar|null>  $row
     */
    private static function required(array $row, string $column): string
    {
        return trim((string) ($row[$column] ?? ''));
    }

    /**
     * @param  array<string, scalar|null>  $row
     */
    private static function integer(array $row, string $column): ?int
    {
        $value = $row[$column] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }
}
