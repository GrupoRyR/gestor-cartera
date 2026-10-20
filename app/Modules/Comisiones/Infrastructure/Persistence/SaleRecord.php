<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_ventas": one SAP sales line (Report 1), written only
 * by the Ingesta listener.
 *
 * @property int $id
 * @property string $clave
 * @property int|null $load_id
 * @property int $tipo_objeto
 * @property int $doc_entry
 * @property int $numero_documento
 * @property int $linea
 * @property string $clase_documento
 * @property string $estado_documento
 * @property CarbonImmutable $fecha_documento
 * @property CarbonImmutable|null $fecha_contabilizacion
 * @property int $slp_code
 * @property string|null $vendedor
 * @property string|null $canal
 * @property string $codigo_cliente
 * @property string|null $cliente
 * @property string|null $nit
 * @property string|null $ciudad
 * @property string|null $codigo_articulo
 * @property string|null $descripcion
 * @property string $total
 * @property string|null $iva
 * @property string|null $moneda
 * @property string|null $trm
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_ventas')]
#[Fillable([
    'clave', 'load_id', 'tipo_objeto', 'doc_entry', 'numero_documento', 'linea', 'clase_documento',
    'estado_documento', 'fecha_documento', 'fecha_contabilizacion', 'slp_code', 'vendedor', 'canal',
    'codigo_cliente', 'cliente', 'nit', 'ciudad', 'codigo_articulo', 'descripcion', 'total', 'iva',
    'moneda', 'trm',
])]
final class SaleRecord extends Model
{
    /** tipo_objeto of an A/R invoice. */
    public const int INVOICE = 13;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'load_id' => 'integer',
            'tipo_objeto' => 'integer',
            'doc_entry' => 'integer',
            'numero_documento' => 'integer',
            'linea' => 'integer',
            'fecha_documento' => 'immutable_date',
            'fecha_contabilizacion' => 'immutable_date',
            'slp_code' => 'integer',
            'total' => 'decimal:2',
            'iva' => 'decimal:2',
            'trm' => 'decimal:6',
        ];
    }
}
