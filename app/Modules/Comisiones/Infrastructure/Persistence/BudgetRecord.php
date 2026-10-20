<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "comisiones_presupuestos": the monthly sales budget of a code,
 * loaded by the analyst (§7.3). It needs no approval.
 *
 * @property int $id
 * @property string $periodo
 * @property int $slp_code
 * @property string $valor
 * @property string|null $canal
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 * @property-read User|null $editor
 */
#[Table(name: 'comisiones_presupuestos')]
#[Fillable(['periodo', 'slp_code', 'valor', 'canal', 'created_by', 'updated_by'])]
final class BudgetRecord extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slp_code' => 'integer',
            'valor' => 'decimal:2',
            'created_by' => 'integer',
            'updated_by' => 'integer',
        ];
    }
}
