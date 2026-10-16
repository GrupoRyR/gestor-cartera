<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Database\Factories\ActivityRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A row of "activities": one entry of a client's activity log (gestión /
 * bitácora), written through Infrastructure\Activity\DatabaseGestionLogger
 * or, for automatic status changes, by RecomputeClientStatuses. Soft-deleted
 * by the portfolio managers and analysts; a removed entry never reads back.
 *
 * @property int $id
 * @property CompanyCode $company The client's company.
 * @property int $client_id
 * @property ActivityType $type
 * @property string $summary
 * @property string|null $details
 * @property string|null $result Outcome of a call.
 * @property int|null $user_id The author; null when the platform wrote it.
 * @property CarbonImmutable $occurred_at
 * @property array<string, scalar|null>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read ClientRecord $client
 * @property-read User|null $user
 */
#[Table(name: 'activities')]
#[Fillable(['company', 'client_id', 'type', 'summary', 'details', 'result', 'user_id', 'occurred_at', 'metadata'])]
#[UseFactory(ActivityRecordFactory::class)]
final class ActivityRecord extends Model
{
    /** @use HasFactory<ActivityRecordFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The client, also when it is soft-deleted.
     *
     * @return BelongsTo<ClientRecord, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientRecord::class, 'client_id')->withTrashed();
    }

    /**
     * The author, also when they are soft-deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'type' => ActivityType::class,
            'user_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'metadata' => 'array',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
