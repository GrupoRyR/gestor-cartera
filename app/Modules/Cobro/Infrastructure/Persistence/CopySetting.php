<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "cobro_copias": who a company copies on the weekly collection
 * mail. Written only by CopyStore.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property list<string> $fixed
 * @property bool $advisor
 * @property list<array{group: string, emails: list<string>}> $managers
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $editor
 */
#[Table(name: 'cobro_copias')]
#[Fillable(['company', 'fixed', 'advisor', 'managers', 'updated_by'])]
final class CopySetting extends Model
{
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
            'company' => CompanyCode::class,
            'fixed' => 'array',
            'advisor' => 'boolean',
            'managers' => 'array',
            'updated_by' => 'integer',
        ];
    }
}
