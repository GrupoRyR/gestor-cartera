<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_cargas": one Ingesta load the module staged, with
 * its row counts. Written only by the Ingesta listener.
 *
 * @property int $id
 * @property int $load_id
 * @property string $dataset
 * @property string $fuente
 * @property CarbonImmutable $corte
 * @property int $filas
 * @property int $insertadas
 * @property int $actualizadas
 * @property CarbonImmutable $recibida_en
 * @property string $estado
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_cargas')]
#[Fillable(['load_id', 'dataset', 'fuente', 'corte', 'filas', 'insertadas', 'actualizadas', 'recibida_en', 'estado'])]
final class CommissionLoad extends Model
{
    public const string ACCEPTED = 'aceptada';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'load_id' => 'integer',
            'corte' => 'immutable_date',
            'filas' => 'integer',
            'insertadas' => 'integer',
            'actualizadas' => 'integer',
            'recibida_en' => 'immutable_datetime',
        ];
    }
}
