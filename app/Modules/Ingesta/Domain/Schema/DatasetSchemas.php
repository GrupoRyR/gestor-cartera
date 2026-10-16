<?php

namespace App\Modules\Ingesta\Domain\Schema;

use App\Modules\Core\Contracts\Enums\Dataset;

/**
 * The schema of every dataset, a mirror of the LOTES registry of
 * etl/esquema.py: same column order, names, types, required flags, natural
 * keys and empty rule. esquema.py is the source of truth; when it changes,
 * change this file too (DatasetSchemasTest fails until both agree).
 *
 * The column lists are laid out like esquema.py (HANA alias, row key, type,
 * required) so the two files can be compared line by line.
 */
final class DatasetSchemas
{
    /**
     * @var array<string, DatasetSchema>
     */
    private static array $schemas = [];

    public static function for(Dataset $dataset): DatasetSchema
    {
        return self::$schemas[$dataset->value] ??= match ($dataset) {
            Dataset::Receivables => new DatasetSchema($dataset, self::receivables()),
            Dataset::Advisors => new DatasetSchema($dataset, self::advisors(), ['slp_code']),
            Dataset::Contacts => new DatasetSchema($dataset, self::contacts(), ['codigo_sn', 'contacto_id']),
            Dataset::Sales => new DatasetSchema($dataset, self::sales(), ['tipo_objeto', 'doc_entry', 'linea'], allowEmpty: true),
            Dataset::Collections => new DatasetSchema($dataset, self::collections(), ['doc_entry_pago', 'linea'], allowEmpty: true),
            Dataset::Offsets => new DatasetSchema($dataset, self::offsets(), ['numero_cruce', 'linea'], allowEmpty: true),
            Dataset::CustomerMaster => new DatasetSchema($dataset, self::customerMaster(), ['codigo_cliente']),
            Dataset::SalesRepMaster => new DatasetSchema($dataset, self::salesRepMaster(), ['slp_code']),
        };
    }

    /**
     * esquema.py CARTERA: one row per invoice installment; no natural key
     * (the query has no unique line id).
     *
     * @return list<Column>
     */
    private static function receivables(): array
    {
        return [
            self::column('SlpCode', 'slp_code', ColumnType::Integer),
            self::column('Email asesor', 'email_asesor', ColumnType::Text),
            self::column('Código SN', 'codigo_sn', ColumnType::Text, required: true),
            self::column('Nombre SN', 'nombre_sn', ColumnType::Text),
            self::column('Nit', 'nit', ColumnType::Text),
            self::column('Grupo', 'grupo', ColumnType::Text),
            self::column('Municipio', 'municipio', ColumnType::Text),
            self::column('Cuenta visible', 'cuenta_visible', ColumnType::Text),
            self::column('Tipo doc', 'tipo_doc', ColumnType::Text),
            self::column('Documento', 'documento', ColumnType::Text, required: true),
            self::column('Documento base', 'documento_base', ColumnType::Text),
            self::column('Referencia 1', 'referencia_1', ColumnType::Text),
            self::column('No. referencia', 'no_referencia', ColumnType::Text),
            self::column('Empleado ventas / compras', 'asesor', ColumnType::Text),
            self::column('Total plazos', 'total_plazos', ColumnType::Integer),
            self::column('No. plazo', 'no_plazo', ColumnType::Integer),
            self::column('Condicion pago documento', 'condicion_pago', ColumnType::Text),
            self::column('Fecha contabilización', 'fecha_contabilizacion', ColumnType::Date),
            self::column('Fecha vencimiento', 'fecha_vencimiento', ColumnType::Date, required: true),
            self::column('Fecha documento', 'fecha_documento', ColumnType::Date),
            self::column('Días', 'dias', ColumnType::Integer, required: true),
            self::column('Límite crédito', 'limite_credito', ColumnType::Decimal2),
            self::column('Importe original', 'importe_original', ColumnType::Decimal2),
            self::column('Base gravable', 'base_gravable', ColumnType::Decimal2),
            self::column('Saldo base', 'saldo_base', ColumnType::Decimal2),
            self::column('Saldo vencido', 'saldo_vencido', ColumnType::Decimal2),
            self::column('Valor abonado', 'valor_abonado', ColumnType::Decimal2),
            self::column('Sin vencer', 'sin_vencer', ColumnType::Decimal2),
            self::column('Valor cheques aplicados', 'valor_cheques', ColumnType::Decimal2),
            self::column('0-30', 'd_0_30', ColumnType::Decimal2),
            self::column('31-60', 'd_31_60', ColumnType::Decimal2),
            self::column('61-90', 'd_61_90', ColumnType::Decimal2),
            self::column('91-120', 'd_91_120', ColumnType::Decimal2),
            self::column('121-150', 'd_121_150', ColumnType::Decimal2),
            self::column('151-180', 'd_151_180', ColumnType::Decimal2),
            self::column('> 180', 'd_mas_180', ColumnType::Decimal2),
            self::column('Saldo', 'saldo', ColumnType::Decimal2, required: true),
            self::column('Rango', 'rango', ColumnType::Text),
        ];
    }

    /**
     * esquema.py ASESORES: SAP sales employees.
     *
     * @return list<Column>
     */
    private static function advisors(): array
    {
        return [
            self::column('SlpCode', 'slp_code', ColumnType::Integer, required: true),
            self::column('Nombre', 'nombre', ColumnType::Text, required: true),
            self::column('Email', 'email', ColumnType::Text),
            self::column('Activo', 'activo', ColumnType::Text),
            self::column('Clientes', 'clientes', ColumnType::Integer),
        ];
    }

    /**
     * esquema.py CONTACTOS: one row per contact person (OCPR) plus one
     * general row per client (OCRD) with contacto_id and nombre null.
     *
     * @return list<Column>
     */
    private static function contacts(): array
    {
        return [
            self::column('Código SN', 'codigo_sn', ColumnType::Text, required: true),
            self::column('Nit', 'nit', ColumnType::Text),
            self::column('Id contacto', 'contacto_id', ColumnType::Integer),
            self::column('Nombre', 'nombre', ColumnType::Text),
            self::column('Cargo', 'cargo', ColumnType::Text),
            self::column('Correo', 'correo', ColumnType::Text),
            self::column('Teléfono', 'telefono', ColumnType::Text),
            self::column('Celular', 'celular', ColumnType::Text),
            self::column('Principal', 'principal', ColumnType::Text, required: true),
            self::column('Activo', 'activo', ColumnType::Text, required: true),
        ];
    }

    /**
     * esquema.py VENTAS: one row per line of an A/R invoice (13) or credit
     * note (14); credit notes come signed negative.
     *
     * @return list<Column>
     */
    private static function sales(): array
    {
        return [
            self::column('Tipo objeto', 'tipo_objeto', ColumnType::Integer, required: true),
            self::column('DocEntry', 'doc_entry', ColumnType::Integer, required: true),
            self::column('Número documento', 'numero_documento', ColumnType::Integer, required: true),
            self::column('Línea', 'linea', ColumnType::Integer, required: true),
            self::column('Clase documento', 'clase_documento', ColumnType::Text, required: true),
            self::column('Estado documento', 'estado_documento', ColumnType::Text, required: true),
            self::column('Fecha documento', 'fecha_documento', ColumnType::Date, required: true),
            self::column('Fecha contabilización', 'fecha_contabilizacion', ColumnType::Date, required: true),
            self::column('SlpCode', 'slp_code', ColumnType::Integer, required: true),
            self::column('Vendedor', 'vendedor', ColumnType::Text),
            self::column('Canal', 'canal', ColumnType::Text),
            self::column('Código cliente', 'codigo_cliente', ColumnType::Text, required: true),
            self::column('Cliente', 'cliente', ColumnType::Text),
            self::column('Nit', 'nit', ColumnType::Text),
            self::column('Ciudad', 'ciudad', ColumnType::Text),
            self::column('Pedido', 'pedido', ColumnType::Text),
            self::column('Id pedido app', 'id_pedido_app', ColumnType::Text),
            self::column('OC cliente', 'oc_cliente', ColumnType::Text),
            self::column('Ciudad envío', 'ciudad_envio', ColumnType::Text),
            self::column('Dirección envío', 'direccion_envio', ColumnType::Text),
            self::column('Marca', 'marca', ColumnType::Text),
            self::column('Familia', 'familia', ColumnType::Text),
            self::column('Código artículo', 'codigo_articulo', ColumnType::Text),
            self::column('Descripción', 'descripcion', ColumnType::Text),
            self::column('Cantidad', 'cantidad', ColumnType::Decimal6, required: true),
            self::column('Costo unitario', 'costo_unitario', ColumnType::Decimal2),
            self::column('Costo total', 'costo_total', ColumnType::Decimal2),
            self::column('Precio lista', 'precio_lista', ColumnType::Decimal2),
            self::column('Precio unitario', 'precio_unitario', ColumnType::Decimal2),
            self::column('Total', 'total', ColumnType::Decimal2, required: true),
            self::column('IVA', 'iva', ColumnType::Decimal2),
            self::column('Moneda', 'moneda', ColumnType::Text),
            self::column('TRM', 'trm', ColumnType::Decimal6),
            self::column('Comentarios', 'comentarios', ColumnType::Text),
        ];
    }

    /**
     * esquema.py RECAUDOS: one row per application of an incoming payment
     * to an invoice (ORCT + RCT2), cancelled payments included.
     *
     * @return list<Column>
     */
    private static function collections(): array
    {
        return [
            self::column('DocEntry pago', 'doc_entry_pago', ColumnType::Integer, required: true),
            self::column('Número pago', 'numero_pago', ColumnType::Integer, required: true),
            self::column('Línea', 'linea', ColumnType::Integer, required: true),
            self::column('Fecha pago', 'fecha_pago', ColumnType::Date, required: true),
            self::column('Estado pago', 'estado_pago', ColumnType::Text, required: true),
            self::column('Tipo documento', 'tipo_documento', ColumnType::Integer, required: true),
            self::column('DocEntry factura', 'doc_entry_factura', ColumnType::Integer, required: true),
            self::column('Número factura', 'numero_factura', ColumnType::Integer),
            self::column('Fecha factura', 'fecha_factura', ColumnType::Date),
            self::column('Fecha vencimiento', 'fecha_vencimiento', ColumnType::Date),
            self::column('Código cliente', 'codigo_cliente', ColumnType::Text, required: true),
            self::column('Nombre cliente', 'nombre_cliente', ColumnType::Text),
            self::column('SlpCode', 'slp_code', ColumnType::Integer, required: true),
            self::column('Vendedor', 'vendedor', ColumnType::Text),
            self::column('Valor factura', 'valor_factura', ColumnType::Decimal2),
            self::column('Base gravable', 'base_gravable', ColumnType::Decimal2),
            self::column('IVA factura', 'iva_factura', ColumnType::Decimal2),
            self::column('Descuento factura', 'descuento_factura', ColumnType::Decimal2),
            self::column('Moneda', 'moneda', ColumnType::Text),
            self::column('TRM', 'trm', ColumnType::Decimal6),
            self::column('Valor aplicado', 'valor_aplicado', ColumnType::Decimal2, required: true),
            self::column('Valor pagado sin IVA', 'valor_pagado_sin_iva', ColumnType::Decimal2, required: true),
            self::column('Comisión SAP', 'comision_sap', ColumnType::Decimal2),
        ];
    }

    /**
     * esquema.py CRUCES: one row per invoice line of an internal
     * reconciliation (OITR + ITR1).
     *
     * @return list<Column>
     */
    private static function offsets(): array
    {
        return [
            self::column('Número cruce', 'numero_cruce', ColumnType::Integer, required: true),
            self::column('Línea', 'linea', ColumnType::Integer, required: true),
            self::column('Fecha cruce', 'fecha_cruce', ColumnType::Date, required: true),
            self::column('Tipo cruce', 'tipo_cruce', ColumnType::Text, required: true),
            self::column('Tipo conciliación', 'tipo_conciliacion', ColumnType::Integer, required: true),
            self::column('Estado', 'estado', ColumnType::Text, required: true),
            self::column('Nit', 'nit', ColumnType::Text),
            self::column('Código cliente', 'codigo_cliente', ColumnType::Text, required: true),
            self::column('Código proveedor', 'codigo_proveedor', ColumnType::Text),
            self::column('Razón social', 'razon_social', ColumnType::Text),
            self::column('DocEntry factura', 'doc_entry_factura', ColumnType::Integer, required: true),
            self::column('Número factura', 'numero_factura', ColumnType::Integer),
            self::column('Fecha factura', 'fecha_factura', ColumnType::Date),
            self::column('SlpCode factura', 'slp_code_factura', ColumnType::Integer, required: true),
            self::column('Vendedor factura', 'vendedor_factura', ColumnType::Text),
            self::column('Valor factura', 'valor_factura', ColumnType::Decimal2),
            self::column('Base gravable', 'base_gravable', ColumnType::Decimal2),
            self::column('IVA factura', 'iva_factura', ColumnType::Decimal2),
            self::column('Valor compensado', 'valor_compensado', ColumnType::Decimal2, required: true),
            self::column('Valor compensado sin IVA', 'valor_compensado_sin_iva', ColumnType::Decimal2, required: true),
            self::column('Usuario', 'usuario', ColumnType::Text),
        ];
    }

    /**
     * esquema.py MAESTRO_CLIENTES: every client (OCRD, CardType 'C').
     *
     * @return list<Column>
     */
    private static function customerMaster(): array
    {
        return [
            self::column('Código cliente', 'codigo_cliente', ColumnType::Text, required: true),
            self::column('Nit', 'nit', ColumnType::Text),
            self::column('Razón social', 'razon_social', ColumnType::Text),
            self::column('SlpCode', 'slp_code', ColumnType::Integer, required: true),
            self::column('Vendedor', 'vendedor', ColumnType::Text),
            self::column('Canal', 'canal', ColumnType::Text),
            self::column('Ciudad', 'ciudad', ColumnType::Text),
            self::column('Código proveedor', 'codigo_proveedor', ColumnType::Text),
            self::column('Estado', 'estado', ColumnType::Text, required: true),
            self::column('Fecha modificación', 'fecha_modificacion', ColumnType::Date),
        ];
    }

    /**
     * esquema.py MAESTRO_VENDEDORES: every sales employee (OSLP, SlpCode >= 0).
     *
     * @return list<Column>
     */
    private static function salesRepMaster(): array
    {
        return [
            self::column('SlpCode', 'slp_code', ColumnType::Integer, required: true),
            self::column('Nombre', 'nombre', ColumnType::Text, required: true),
            self::column('Estado', 'estado', ColumnType::Text, required: true),
            self::column('Correo', 'correo', ColumnType::Text),
            self::column('Canal', 'canal', ColumnType::Text),
            self::column('Zona', 'zona', ColumnType::Text),
            self::column('Fecha modificación', 'fecha_modificacion', ColumnType::Date),
        ];
    }

    /**
     * A column in the argument order of esquema.py's Columna().
     */
    private static function column(string $sourceHeader, string $name, ColumnType $type, bool $required = false): Column
    {
        return new Column($name, $type, $required, $sourceHeader);
    }
}
