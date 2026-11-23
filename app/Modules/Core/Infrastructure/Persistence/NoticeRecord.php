<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Database\Factories\NoticeRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "notices": one in-app notice (aviso) of one user, written through
 * Infrastructure\Notices\EloquentAvisoService.
 *
 * @property int $id
 * @property int $user_id The recipient.
 * @property string|null $company CompanyCode value.
 * @property string $kind
 * @property string $title
 * @property string|null $body
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string|null $key
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 */
#[Table(name: 'notices')]
#[Fillable([
    'user_id', 'company', 'kind', 'title', 'body', 'subject_type', 'subject_id', 'key', 'data',
    'read_at', 'resolved_at',
])]
#[UseFactory(NoticeRecordFactory::class)]
final class NoticeRecord extends Model
{
    /** @use HasFactory<NoticeRecordFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'subject_id' => 'integer',
            'data' => 'array',
            'read_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
