<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_obr": a manual OBR/Escudos sale with its own rate
 * (§5.5), captured with support and approved by another user.
 *
 * @property int $id
 * @property string $periodo
 * @property int $slp_code
 * @property string $descripcion
 * @property string $valor_vendido
 * @property string $tasa
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
#[Table(name: 'comisiones_obr')]
#[Fillable([
    'periodo', 'slp_code', 'descripcion', 'valor_vendido', 'tasa', 'soporte', 'estado', 'created_by',
    'revisado_por', 'revisado_en', 'motivo_rechazo',
])]
final class ObrRecord extends Model
{
    use ReviewedCapture;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            ...$this->reviewCasts(),
            'slp_code' => 'integer',
            'valor_vendido' => 'decimal:2',
            'tasa' => 'decimal:4',
        ];
    }
}
