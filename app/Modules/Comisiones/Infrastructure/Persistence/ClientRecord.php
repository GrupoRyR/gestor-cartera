<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_clientes": the current master row of a client
 * (Report 5), written only by the Ingesta listener.
 *
 * @property int $id
 * @property string $codigo_cliente
 * @property string|null $nit
 * @property string|null $razon_social
 * @property int $slp_code
 * @property string|null $vendedor
 * @property string|null $canal
 * @property string|null $ciudad
 * @property string|null $codigo_proveedor
 * @property string $estado
 * @property CarbonImmutable|null $fecha_modificacion
 * @property int|null $load_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_clientes')]
#[Fillable([
    'codigo_cliente', 'nit', 'razon_social', 'slp_code', 'vendedor', 'canal', 'ciudad', 'codigo_proveedor',
    'estado', 'fecha_modificacion', 'load_id',
])]
final class ClientRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slp_code' => 'integer',
            'fecha_modificacion' => 'immutable_date',
            'load_id' => 'integer',
        ];
    }
}
