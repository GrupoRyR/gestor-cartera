<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "comisiones_asignaciones": the seller of a client from a date on
 * (Report 5 history), written only by the Ingesta listener.
 *
 * @property int $id
 * @property string $codigo_cliente
 * @property int $slp_code
 * @property CarbonImmutable $vigente_desde
 * @property int|null $load_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'comisiones_asignaciones')]
#[Fillable(['codigo_cliente', 'slp_code', 'vigente_desde', 'load_id'])]
final class ClientAssignmentRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slp_code' => 'integer',
            'vigente_desde' => 'immutable_date',
            'load_id' => 'integer',
        ];
    }
}
