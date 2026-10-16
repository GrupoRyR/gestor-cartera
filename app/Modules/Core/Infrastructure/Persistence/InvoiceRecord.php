<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Database\Factories\InvoiceRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "invoices": one open item (an invoice installment, a credit note,
 * a payment or a journal entry) of a client in one snapshot. Rows belong to
 * their snapshot and are never updated. Amounts are exact decimal strings.
 *
 * @property int $id
 * @property int $portfolio_load_id
 * @property CompanyCode $company
 * @property int $client_id
 * @property string $document_number
 * @property string $document_type SAP transaction type (JDT1.TransType).
 * @property int|null $installment
 * @property CarbonImmutable $issued_at
 * @property CarbonImmutable $due_at
 * @property string $amount
 * @property string $balance Negative for credit notes and unapplied payments.
 * @property int $days_overdue From the due date to the cutoff date: positive = past due.
 * @property int|null $advisor_code
 * @property-read PortfolioLoadRecord $portfolioLoad
 * @property-read ClientRecord $client
 */
#[Table(name: 'invoices', timestamps: false)]
#[Fillable([
    'portfolio_load_id', 'company', 'client_id', 'document_number', 'document_type', 'installment',
    'issued_at', 'due_at', 'amount', 'balance', 'days_overdue', 'advisor_code',
])]
#[UseFactory(InvoiceRecordFactory::class)]
final class InvoiceRecord extends Model
{
    /** @use HasFactory<InvoiceRecordFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<PortfolioLoadRecord, $this>
     */
    public function portfolioLoad(): BelongsTo
    {
        return $this->belongsTo(PortfolioLoadRecord::class, 'portfolio_load_id');
    }

    /**
     * The client, also when it is soft-deleted.
     *
     * @return BelongsTo<ClientRecord, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientRecord::class, 'client_id')->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'portfolio_load_id' => 'integer',
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'installment' => 'integer',
            'issued_at' => 'immutable_date',
            'due_at' => 'immutable_date',
            'amount' => 'decimal:2',
            'balance' => 'decimal:2',
            'days_overdue' => 'integer',
            'advisor_code' => 'integer',
        ];
    }
}
