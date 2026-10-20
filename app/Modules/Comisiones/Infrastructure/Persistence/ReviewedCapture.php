<?php

namespace App\Modules\Comisiones\Infrastructure\Persistence;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The creator and the reviewer of a capture that needs an approval, and the
 * scope of the approved ones. The model casts "estado" to CaptureStatus.
 */
trait ReviewedCapture
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
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por')->withTrashed();
    }

    /**
     * Only the approved captures, the ones a calculation reads.
     *
     * @param  Builder<static>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('estado', CaptureStatus::Approved->value);
    }

    /**
     * @return array<string, string>
     */
    protected function reviewCasts(): array
    {
        return [
            'estado' => CaptureStatus::class,
            'created_by' => 'integer',
            'revisado_por' => 'integer',
            'revisado_en' => 'immutable_datetime',
        ];
    }
}
