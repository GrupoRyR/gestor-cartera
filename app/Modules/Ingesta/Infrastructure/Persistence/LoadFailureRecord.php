<?php

namespace App\Modules\Ingesta\Infrastructure\Persistence;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "ingesta_load_failures": one load failure report, stored once
 * per report_id. Written only by RecordLoadFailure. Times are in the app
 * timezone.
 *
 * @property int $id
 * @property string $report_id
 * @property FailureSource $source
 * @property string $run_id
 * @property CompanyCode $company
 * @property Dataset $dataset
 * @property CarbonImmutable $slot_at
 * @property FailureStage $stage
 * @property FailureCause $cause
 * @property bool $transient
 * @property int $attempt
 * @property int $max_attempts
 * @property bool $final
 * @property string $message
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 */
#[Table(name: 'ingesta_load_failures', timestamps: false)]
#[Fillable([
    'report_id', 'source', 'run_id', 'company', 'dataset', 'slot_at', 'stage', 'cause', 'transient',
    'attempt', 'max_attempts', 'final', 'message', 'occurred_at', 'received_at',
])]
final class LoadFailureRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => FailureSource::class,
            'company' => CompanyCode::class,
            'dataset' => Dataset::class,
            'slot_at' => 'immutable_datetime',
            'stage' => FailureStage::class,
            'cause' => FailureCause::class,
            'transient' => 'boolean',
            'attempt' => 'integer',
            'max_attempts' => 'integer',
            'final' => 'boolean',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
