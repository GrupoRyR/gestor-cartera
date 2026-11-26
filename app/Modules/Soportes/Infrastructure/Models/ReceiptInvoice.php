<?php

namespace App\Modules\Soportes\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An open invoice a receipt covers (soporte_facturas), copied at upload:
 * the SAP document number identifies it, because Core's invoice id changes
 * with every snapshot. balance is a decimal string with two places.
 *
 * @property int $id
 * @property int $soporte_id
 * @property string $document_number
 * @property string $document_type
 * @property CarbonImmutable $due_at
 * @property string $balance
 * @property int $days_overdue
 * @property-read Receipt $receipt
 */
final class ReceiptInvoice extends Model
{
    protected $table = 'soporte_facturas';

    protected $fillable = ['soporte_id', 'document_number', 'document_type', 'due_at', 'balance', 'days_overdue'];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'date',
            'balance' => 'decimal:2',
            'days_overdue' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Receipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'soporte_id');
    }
}
