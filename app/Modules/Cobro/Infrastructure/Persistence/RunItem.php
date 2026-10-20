<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "cobro_run_items": one client of a run and its delivery
 * outcome. Written by RunPlanner, SendRunItem and MarkItemBounced.
 *
 * @property int $id
 * @property int $run_id
 * @property int $client_id
 * @property string $client_code
 * @property string $client_name
 * @property string|null $tax_id
 * @property list<string> $recipients
 * @property string $amount Exact decimal string.
 * @property int $invoice_count
 * @property ItemStatus $status
 * @property string|null $exclusion_reason
 * @property int|null $sent_message_id
 * @property string|null $internet_message_id
 * @property CarbonImmutable|null $sent_at
 * @property string|null $error
 * @property string|null $bounce_reason
 * @property string|null $bounced_recipient
 * @property CarbonImmutable|null $bounced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Run $run
 */
#[Table(name: 'cobro_run_items')]
#[Fillable([
    'run_id', 'client_id', 'client_code', 'client_name', 'tax_id', 'recipients', 'amount', 'invoice_count', 'status',
    'exclusion_reason', 'sent_message_id', 'internet_message_id', 'sent_at', 'error', 'bounce_reason', 'bounced_recipient', 'bounced_at',
])]
final class RunItem extends Model
{
    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'client_id' => 'integer',
            'recipients' => 'array',
            'amount' => 'decimal:2',
            'invoice_count' => 'integer',
            'status' => ItemStatus::class,
            'sent_message_id' => 'integer',
            'sent_at' => 'immutable_datetime',
            'bounced_at' => 'immutable_datetime',
        ];
    }
}
