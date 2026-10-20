<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "cobro_exclusiones": a client left out of the collection mail.
 * Written only by ExclusionList.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property int $client_id
 * @property string $client_code
 * @property string $client_name
 * @property string|null $tax_id
 * @property string $reason
 * @property CarbonImmutable|null $expires_on
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $creator
 */
#[Table(name: 'cobro_exclusiones')]
#[Fillable(['company', 'client_id', 'client_code', 'client_name', 'tax_id', 'reason', 'expires_on', 'created_by'])]
final class Exclusion extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * Exclusions that apply on $today: without an expiry date or expiring
     * on $today or later (ExclusionPeriod).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, CarbonImmutable $today): void
    {
        $query->where(fn (Builder $active) => $active
            ->whereNull('expires_on')
            ->orWhereDate('expires_on', '>=', $today->format('Y-m-d')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'expires_on' => 'immutable_date',
            'created_by' => 'integer',
        ];
    }
}
