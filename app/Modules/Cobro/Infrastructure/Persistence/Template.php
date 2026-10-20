<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "cobro_templates": the texts a company saved for a mail.
 * Written only by TemplateStore.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property RunKind $kind
 * @property string $subject
 * @property string $intro
 * @property string $closing
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $editor
 */
#[Table(name: 'cobro_templates')]
#[Fillable(['company', 'kind', 'subject', 'intro', 'closing', 'updated_by'])]
final class Template extends Model
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
            'kind' => RunKind::class,
            'updated_by' => 'integer',
        ];
    }
}
