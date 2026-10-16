<?php

namespace App\Modules\Ingesta\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of "ingesta_loads": one entry of the load history, accepted or
 * rejected. Written only by EloquentIngestaSink. Amounts are exact decimal
 * strings.
 *
 * @property int $id
 * @property string $batch_id
 * @property string|null $accepted_batch_id The batch id, only while the load is accepted.
 * @property Dataset $dataset
 * @property CompanyCode $company
 * @property CarbonImmutable $cutoff_date
 * @property LoadSource $source
 * @property LoadStatus $status
 * @property int $row_count
 * @property string|null $total Cartera only: sum of "saldo".
 * @property string|null $overdue Cartera only: sum of "saldo" where "dias" > 0.
 * @property list<string>|null $columns Row keys in stored order; null for a rejected load.
 * @property string|null $error
 * @property array<string, list<string>>|null $errors
 * @property CarbonImmutable $received_at
 * @property int|null $received_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $receivedBy
 */
#[Table(name: 'ingesta_loads')]
#[Fillable([
    'batch_id', 'accepted_batch_id', 'dataset', 'company', 'cutoff_date', 'source', 'status', 'row_count',
    'total', 'overdue', 'columns', 'error', 'errors', 'received_at', 'received_by',
])]
final class IngestaLoad extends Model
{
    /**
     * The uploader of a manual load, also when they are soft-deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }

    /**
     * @return HasMany<IngestaLoadChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(IngestaLoadChunk::class, 'load_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dataset' => Dataset::class,
            'company' => CompanyCode::class,
            'cutoff_date' => 'immutable_date',
            'source' => LoadSource::class,
            'status' => LoadStatus::class,
            'row_count' => 'integer',
            'total' => 'decimal:2',
            'overdue' => 'decimal:2',
            'columns' => 'array',
            'errors' => 'array',
            'received_at' => 'immutable_datetime',
            'received_by' => 'integer',
        ];
    }
}
