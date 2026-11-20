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
 * A row of "cobro_firmas": who signs a company's Cobro mails. Written only
 * by SignatureStore.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property string $name
 * @property string $title
 * @property string $mobile
 * @property string|null $extension
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $editor
 */
#[Table(name: 'cobro_firmas')]
#[Fillable(['company', 'name', 'title', 'mobile', 'extension', 'updated_by'])]
final class SignatureSetting extends Model
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
            'updated_by' => 'integer',
        ];
    }
}
