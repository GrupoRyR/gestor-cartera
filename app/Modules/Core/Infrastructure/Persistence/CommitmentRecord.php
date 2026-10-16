<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Database\Factories\CommitmentRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "commitments": a payment commitment (compromiso de pago) of a
 * client, written through Infrastructure\Commitments\EloquentCompromisoService
 * and broken by the daily task. Never deleted. The amount is an exact
 * decimal string; the due date is a calendar date (America/Bogota).
 *
 * @property int $id
 * @property CompanyCode $company The client's company.
 * @property int $client_id
 * @property int|null $advisor_code The client's OSLP.SlpCode when it was created.
 * @property string $amount
 * @property CarbonImmutable $due_date
 * @property CommitmentStatus $status
 * @property string|null $notes
 * @property int $created_by
 * @property CarbonImmutable|null $fulfilled_at
 * @property CarbonImmutable|null $broken_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ClientRecord $client
 * @property-read User $creator
 */
#[Table(name: 'commitments')]
#[Fillable([
    'company', 'client_id', 'advisor_code', 'amount', 'due_date', 'status', 'notes', 'created_by',
    'fulfilled_at', 'broken_at', 'cancelled_at', 'cancellation_reason',
])]
#[UseFactory(CommitmentRecordFactory::class)]
final class CommitmentRecord extends Model
{
    /** @use HasFactory<CommitmentRecordFactory> */
    use HasFactory;

    /**
     * The column default, so a new commitment reads it before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pendiente',
    ];

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
     * The user who registered it, also when they are soft-deleted.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'advisor_code' => 'integer',
            'amount' => 'decimal:2',
            'due_date' => 'immutable_date',
            'status' => CommitmentStatus::class,
            'created_by' => 'integer',
            'fulfilled_at' => 'immutable_datetime',
            'broken_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
