<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_recaudos": one SAP payment applied to an invoice
 * (Report 2), written only by the Ingesta listener.
 *
 * @property int $id
 * @property string $clave
 * @property int|null $load_id
 * @property int $doc_entry_pago
 * @property int $numero_pago
 * @property int $linea
 * @property CarbonImmutable $fecha_pago
 * @property string $estado_pago
 * @property int $tipo_documento
 * @property int $doc_entry_factura
 * @property int|null $numero_factura
 * @property CarbonImmutable|null $fecha_factura
 * @property CarbonImmutable|null $fecha_vencimiento
 * @property string $codigo_cliente
 * @property string|null $nombre_cliente
 * @property int $slp_code
 * @property string|null $vendedor
 * @property string|null $valor_factura
 * @property string|null $base_gravable
 * @property string|null $iva_factura
 * @property string|null $descuento_factura
 * @property string|null $moneda
 * @property string|null $trm
 * @property string $valor_aplicado
 * @property string $valor_pagado_sin_iva
 * @property string|null $comision_sap
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_recaudos')]
#[Fillable([
    'clave', 'load_id', 'doc_entry_pago', 'numero_pago', 'linea', 'fecha_pago', 'estado_pago',
    'tipo_documento', 'doc_entry_factura', 'numero_factura', 'fecha_factura', 'fecha_vencimiento',
    'codigo_cliente', 'nombre_cliente', 'slp_code', 'vendedor', 'valor_factura', 'base_gravable',
    'iva_factura', 'descuento_factura', 'moneda', 'trm', 'valor_aplicado', 'valor_pagado_sin_iva',
    'comision_sap',
])]
final class PaymentRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'load_id' => 'integer',
            'doc_entry_pago' => 'integer',
            'numero_pago' => 'integer',
            'linea' => 'integer',
            'fecha_pago' => 'immutable_date',
            'tipo_documento' => 'integer',
            'doc_entry_factura' => 'integer',
            'numero_factura' => 'integer',
            'fecha_factura' => 'immutable_date',
            'fecha_vencimiento' => 'immutable_date',
            'slp_code' => 'integer',
            'valor_factura' => 'decimal:2',
            'base_gravable' => 'decimal:2',
            'iva_factura' => 'decimal:2',
            'descuento_factura' => 'decimal:2',
            'trm' => 'decimal:6',
            'valor_aplicado' => 'decimal:2',
            'valor_pagado_sin_iva' => 'decimal:2',
            'comision_sap' => 'decimal:2',
        ];
    }
}
