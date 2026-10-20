<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_cruces": a SAP crossing (Report 4, origen "sap",
 * written by the Ingesta listener and stored as approved) or a manual
 * crossing captured with support (origen "manual"), which needs an approval.
 *
 * "estado_cruce" is the SAP status of the crossing (anulado | vigente);
 * "estado" is the review state of the capture.
 *
 * @property int $id
 * @property string $clave
 * @property string $origen
 * @property int|null $load_id
 * @property int|null $numero_cruce
 * @property int|null $linea
 * @property CarbonImmutable $fecha_cruce
 * @property string $tipo_cruce
 * @property int|null $tipo_conciliacion
 * @property string $estado_cruce
 * @property string|null $nit
 * @property string $codigo_cliente
 * @property string|null $codigo_proveedor
 * @property string|null $razon_social
 * @property int|null $doc_entry_factura
 * @property string|null $numero_factura
 * @property CarbonImmutable|null $fecha_factura
 * @property int|null $slp_code_factura
 * @property string|null $vendedor_factura
 * @property string|null $valor_factura
 * @property string|null $base_gravable
 * @property string|null $iva_factura
 * @property string $valor_compensado
 * @property string|null $valor_compensado_sin_iva
 * @property string|null $usuario
 * @property int|null $slp_code_capturado
 * @property string|null $soporte
 * @property CaptureStatus $estado
 * @property int|null $created_by
 * @property int|null $revisado_por
 * @property CarbonImmutable|null $revisado_en
 * @property string|null $motivo_rechazo
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 * @property-read User|null $reviewer
 */
#[Table(name: 'comisiones_cruces')]
#[Fillable([
    'clave', 'origen', 'load_id', 'numero_cruce', 'linea', 'fecha_cruce', 'tipo_cruce', 'tipo_conciliacion',
    'estado_cruce', 'nit', 'codigo_cliente', 'codigo_proveedor', 'razon_social', 'doc_entry_factura',
    'numero_factura', 'fecha_factura', 'slp_code_factura', 'vendedor_factura', 'valor_factura',
    'base_gravable', 'iva_factura', 'valor_compensado', 'valor_compensado_sin_iva', 'usuario',
    'slp_code_capturado', 'soporte', 'estado', 'created_by', 'revisado_por', 'revisado_en', 'motivo_rechazo',
])]
final class CrossingRecord extends Model
{
    use ReviewedCapture;

    public const string SAP = 'sap';

    public const string MANUAL = 'manual';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->reviewCasts(),
            'load_id' => 'integer',
            'numero_cruce' => 'integer',
            'linea' => 'integer',
            'fecha_cruce' => 'immutable_date',
            'tipo_conciliacion' => 'integer',
            'doc_entry_factura' => 'integer',
            'fecha_factura' => 'immutable_date',
            'slp_code_factura' => 'integer',
            'valor_factura' => 'decimal:2',
            'base_gravable' => 'decimal:2',
            'iva_factura' => 'decimal:2',
            'valor_compensado' => 'decimal:2',
            'valor_compensado_sin_iva' => 'decimal:2',
            'slp_code_capturado' => 'integer',
        ];
    }
}
