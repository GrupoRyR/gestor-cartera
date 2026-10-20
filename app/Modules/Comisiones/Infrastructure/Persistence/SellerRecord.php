<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_vendedores": a SAP seller (Report 6), written only by
 * the Ingesta listener.
 *
 * @property int $id
 * @property int $slp_code
 * @property string $nombre
 * @property string $estado
 * @property string|null $correo
 * @property string|null $canal
 * @property string|null $zona
 * @property CarbonImmutable|null $fecha_modificacion
 * @property int|null $load_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_vendedores')]
#[Fillable(['slp_code', 'nombre', 'estado', 'correo', 'canal', 'zona', 'fecha_modificacion', 'load_id'])]
final class SellerRecord extends Model
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
