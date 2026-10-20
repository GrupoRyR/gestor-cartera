<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_ajustes": a reimbursement (reintegro) or a deduction
 * (descuento) of a code (§5.7), captured with support and approved by
 * another user. The amount is always positive; the type gives the sign.
 *
 * @property int $id
 * @property string $periodo
 * @property int $slp_code
 * @property string $tipo
 * @property string $valor
 * @property string $descripcion
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
#[Table(name: 'comisiones_ajustes')]
#[Fillable([
    'periodo', 'slp_code', 'tipo', 'valor', 'descripcion', 'soporte', 'estado', 'created_by', 'revisado_por',
    'revisado_en', 'motivo_rechazo',
])]
final class AdjustmentRecord extends Model
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
            'valor' => 'decimal:2',
        ];
    }
}
