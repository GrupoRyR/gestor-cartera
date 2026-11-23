<?php

namespace App\Modules\Soportes\Infrastructure\Models;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Database\Factories\ReceiptFactory;
use App\Modules\Soportes\Domain\PaymentMethod;
use App\Modules\Soportes\Domain\ReceiptStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payment receipt (soportes). The client fields are copies taken at
 * upload; client_id is Core's client id, read live only to decide whether an
 * advisor may open the receipt. amount is a decimal string with two places,
 * as the database returns it.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property int $client_id
 * @property string $client_code
 * @property string $client_name
 * @property int|null $advisor_code
 * @property string $amount
 * @property CarbonImmutable $paid_at
 * @property PaymentMethod|null $method
 * @property string|null $note
 * @property ReceiptStatus $status
 * @property string|null $receipt_number
 * @property string|null $rejection_reason
 * @property int $uploaded_by
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reviewed_at
 * @property-read CarbonImmutable $created_at
 * @property-read Collection<int, ReceiptInvoice> $invoices
 * @property-read Collection<int, ReceiptFile> $files
 * @property-read User $uploader
 * @property-read User|null $reviewer
 */
#[UseFactory(ReceiptFactory::class)]
final class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    protected $table = 'soportes';

    protected $fillable = [
        'company', 'client_id', 'client_code', 'client_name', 'advisor_code', 'amount', 'paid_at', 'method', 'note',
        'status', 'receipt_number', 'rejection_reason', 'uploaded_by', 'reviewed_by', 'reviewed_at',
    ];

    /**
     * A new receipt is pending; the column default says the same, this keeps
     * the attribute set before the first save.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pendiente',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'advisor_code' => 'integer',
            'amount' => 'decimal:2',
            'paid_at' => 'date',
            'method' => PaymentMethod::class,
            'status' => ReceiptStatus::class,
            'uploaded_by' => 'integer',
            'reviewed_by' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ReceiptInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(ReceiptInvoice::class, 'soporte_id');
    }

    /**
     * @return HasMany<ReceiptFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ReceiptFile::class, 'soporte_id');
    }

    /**
     * Users are soft-deleted; the receipt still names who uploaded it.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }

    /**
     * Receipts still waiting for the analyst.
     *
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ReceiptStatus::Pending->value);
    }

    /**
     * Receipts of these companies; none for an empty list.
     *
     * @param  Builder<self>  $query
     * @param  list<CompanyCode>  $codes
     */
    public function scopeForCompanies(Builder $query, array $codes): void
    {
        $query->whereIn('company', array_map(fn (CompanyCode $code): string => $code->value, $codes));
    }
}
