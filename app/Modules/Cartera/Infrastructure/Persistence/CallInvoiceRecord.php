<?php

namespace App\Modules\Cartera\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "cartera_call_invoices": an open invoice a call was about,
 * copied when the call was logged. invoice_id is Core's invoice id of that
 * snapshot; balance is a decimal string with two places.
 *
 * @property int $id
 * @property int $cartera_call_id
 * @property int $invoice_id
 * @property string $document_number
 * @property string $document_type
 * @property CarbonImmutable $due_at
 * @property string $balance
 * @property-read CallRecord $call
 */
#[Table(name: 'cartera_call_invoices', timestamps: false)]
#[Fillable(['cartera_call_id', 'invoice_id', 'document_number', 'document_type', 'due_at', 'balance'])]
final class CallInvoiceRecord extends Model
{
    /**
     * @return BelongsTo<CallRecord, $this>
     */
    public function call(): BelongsTo
    {
        return $this->belongsTo(CallRecord::class, 'cartera_call_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cartera_call_id' => 'integer',
            'invoice_id' => 'integer',
            'due_at' => 'immutable_date',
            'balance' => 'decimal:2',
        ];
    }
}
