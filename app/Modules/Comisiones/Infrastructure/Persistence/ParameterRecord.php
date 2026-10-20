<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "comisiones_parametros": one version of a parameter. Written
 * only by the seed migration and ParameterVersions.
 *
 * @property int $id
 * @property string $tipo
 * @property string $clave
 * @property CarbonImmutable $vigente_desde
 * @property CarbonImmutable|null $vigente_hasta
 * @property array<string, mixed> $datos
 * @property string|null $motivo
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 */
#[Table(name: 'comisiones_parametros')]
#[Fillable(['tipo', 'clave', 'vigente_desde', 'vigente_hasta', 'datos', 'motivo', 'created_by'])]
final class ParameterRecord extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * The row as ParameterMapper reads it.
     *
     * @return array{tipo: string, clave: string, vigente_desde: string, vigente_hasta: string|null, datos: array<string, mixed>}
     */
    public function toMapperRecord(): array
    {
        return [
            'tipo' => $this->tipo,
            'clave' => $this->clave,
            'vigente_desde' => $this->vigente_desde->format('Y-m-d'),
            'vigente_hasta' => $this->vigente_hasta?->format('Y-m-d'),
            'datos' => $this->datos,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vigente_desde' => 'immutable_date',
            'vigente_hasta' => 'immutable_date',
            'datos' => 'array',
            'created_by' => 'integer',
        ];
    }
}
