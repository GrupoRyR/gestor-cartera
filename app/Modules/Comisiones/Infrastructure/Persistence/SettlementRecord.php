<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_liquidaciones": one version of the settlement of a
 * period. Written only by EloquentSettlements; read the Settlement through it.
 *
 * @property int $id
 * @property string $periodo
 * @property int $version
 * @property int $revision
 * @property string $estado
 * @property string $calculado_por
 * @property CarbonImmutable $calculado_en
 * @property string|null $aprobado_por
 * @property CarbonImmutable|null $aprobado_en
 * @property string|null $cerrado_por
 * @property CarbonImmutable|null $cerrado_en
 * @property string|null $motivo
 * @property int|null $version_anterior
 * @property array<string, string> $justificaciones
 * @property string $resultado
 * @property string $resultado_sha256
 * @property string $total_nomina
 * @property int $alertas_bloqueantes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_liquidaciones')]
#[Hidden(['resultado'])]
#[Fillable([
    'periodo', 'version', 'revision', 'estado', 'calculado_por', 'calculado_en', 'aprobado_por', 'aprobado_en',
    'cerrado_por', 'cerrado_en', 'motivo', 'version_anterior', 'justificaciones', 'resultado', 'resultado_sha256',
    'total_nomina', 'alertas_bloqueantes',
])]
final class SettlementRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'revision' => 'integer',
            'calculado_en' => 'immutable_datetime',
            'aprobado_en' => 'immutable_datetime',
            'cerrado_en' => 'immutable_datetime',
            'version_anterior' => 'integer',
            'justificaciones' => 'array',
            'total_nomina' => 'decimal:2',
            'alertas_bloqueantes' => 'integer',
        ];
    }
}
