<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of "cobro_runs": one batch of collection mail. Written by
 * RunPlanner and RunFinisher.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property RunKind $kind
 * @property RunStatus $status
 * @property CarbonImmutable $scheduled_for
 * @property CarbonImmutable|null $cutoff_date
 * @property int|null $triggered_by
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $trigger
 */
#[Table(name: 'cobro_runs')]
#[Fillable(['company', 'kind', 'status', 'scheduled_for', 'cutoff_date', 'triggered_by', 'started_at', 'finished_at'])]
final class Run extends Model
{
    /**
     * @return HasMany<RunItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(RunItem::class, 'run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function trigger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'kind' => RunKind::class,
            'status' => RunStatus::class,
            'scheduled_for' => 'immutable_datetime',
            'cutoff_date' => 'immutable_date',
            'triggered_by' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
