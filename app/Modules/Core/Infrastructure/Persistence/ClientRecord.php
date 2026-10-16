<?php

namespace App\Modules\Core\Infrastructure\Persistence;

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Database\Factories\ClientRecordFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A row of "clients": a client of one company (OCRD) with its SAP data, the
 * person in charge of its collection and the balance and status of the
 * latest snapshot, cached for the portfolio screens. Soft-deleted: a removed
 * client keeps its snapshot history. Amounts are exact decimal strings.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property string $code OCRD.CardCode, unique within the company.
 * @property string $name
 * @property string $tax_id NIT (OCRD.LicTradNum).
 * @property string|null $group
 * @property string|null $city
 * @property string|null $payment_terms
 * @property string|null $credit_limit
 * @property int|null $advisor_code The current OSLP.SlpCode of the client.
 * @property int|null $manager_user_id The person in charge of the collection (gestor responsable).
 * @property ClientStatus $status
 * @property CarbonImmutable|null $status_changed_at
 * @property string $balance Total balance in the latest snapshot.
 * @property string $overdue Balance past due in the latest snapshot.
 * @property int $max_days_overdue Most days past due of an item that still owes money.
 * @property CarbonImmutable|null $last_activity_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, InvoiceRecord> $invoices
 * @property-read Collection<int, ContactRecord> $contacts
 */
#[Table(name: 'clients')]
#[Fillable([
    'company', 'code', 'name', 'tax_id', 'group', 'city', 'payment_terms', 'credit_limit', 'advisor_code',
    'manager_user_id', 'status', 'status_changed_at', 'balance', 'overdue', 'max_days_overdue', 'last_activity_at',
])]
#[UseFactory(ClientRecordFactory::class)]
final class ClientRecord extends Model
{
    /** @use HasFactory<ClientRecordFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * The column defaults, so a new client reads them before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'al_dia',
        'balance' => '0.00',
        'overdue' => '0.00',
        'max_days_overdue' => 0,
    ];

    /**
     * The client's items in every snapshot.
     *
     * @return HasMany<InvoiceRecord, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(InvoiceRecord::class, 'client_id');
    }

    /**
     * @return HasMany<ContactRecord, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(ContactRecord::class, 'client_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'credit_limit' => 'decimal:2',
            'advisor_code' => 'integer',
            'manager_user_id' => 'integer',
            'status' => ClientStatus::class,
            'status_changed_at' => 'immutable_datetime',
            'balance' => 'decimal:2',
            'overdue' => 'decimal:2',
            'max_days_overdue' => 'integer',
            'last_activity_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
