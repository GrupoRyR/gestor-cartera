<?php

namespace App\Modules\Ingesta\Infrastructure\Persistence;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "ingesta_load_alerts": one alert sent, unique per company,
 * dataset, slot and kind. SendLoadFailureAlert writes it, with
 * insert-or-ignore, as its claim to send. Times are in the app timezone.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property Dataset $dataset
 * @property CarbonImmutable $slot_at
 * @property LoadAlertKind $kind
 * @property int|null $load_failure_id
 * @property CarbonImmutable $created_at
 */
#[Table(name: 'ingesta_load_alerts', timestamps: false)]
#[Fillable(['company', 'dataset', 'slot_at', 'kind', 'load_failure_id', 'created_at'])]
final class LoadAlertRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'dataset' => Dataset::class,
            'slot_at' => 'immutable_datetime',
            'kind' => LoadAlertKind::class,
            'load_failure_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
