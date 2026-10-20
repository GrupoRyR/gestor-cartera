<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_reasignaciones": a user's correction of the seller of
 * a crossing, with support (§5.4). The latest approved one of a crossing is
 * its seller override.
 *
 * @property int $id
 * @property string $clave_cruce
 * @property int $slp_code
 * @property string $motivo
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
#[Table(name: 'comisiones_reasignaciones')]
#[Fillable([
    'clave_cruce', 'slp_code', 'motivo', 'soporte', 'estado', 'created_by', 'revisado_por', 'revisado_en',
    'motivo_rechazo',
])]
final class ReassignmentRecord extends Model
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
        ];
    }
}
