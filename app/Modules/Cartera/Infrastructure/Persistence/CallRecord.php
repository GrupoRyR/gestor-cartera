<?php

namespace App\Modules\Cartera\Infrastructure\Persistence;

use App\Modules\Cartera\Database\Factories\CallRecordFactory;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A row of "cartera_calls": one call the collection team logged, written by
 * Application\Calls\LogCall. client_id, commitment_id and activity_id are
 * Core's ids, without foreign keys. A follow-up is open while follow_up_on
 * is set and follow_up_closed_at is null.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property int $client_id
 * @property int $user_id
 * @property CarbonImmutable $occurred_at
 * @property CallChannel $channel
 * @property string $phone
 * @property string|null $contact_name
 * @property CallOutcome $outcome
 * @property string|null $notes
 * @property CallAgreement $agreement
 * @property int|null $commitment_id
 * @property int|null $activity_id
 * @property CarbonImmutable|null $follow_up_on
 * @property CarbonImmutable|null $follow_up_notified_at
 * @property CarbonImmutable|null $follow_up_closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Collection<int, CallInvoiceRecord> $invoices
 */
#[Table(name: 'cartera_calls')]
#[Fillable([
    'company', 'client_id', 'user_id', 'occurred_at', 'channel', 'phone', 'contact_name', 'outcome', 'notes',
    'agreement', 'commitment_id', 'activity_id', 'follow_up_on', 'follow_up_notified_at', 'follow_up_closed_at',
])]
#[UseFactory(CallRecordFactory::class)]
final class CallRecord extends Model
{
    /** @use HasFactory<CallRecordFactory> */
    use HasFactory;

    /**
     * @return HasMany<CallInvoiceRecord, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(CallInvoiceRecord::class, 'cartera_call_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'user_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'channel' => CallChannel::class,
            'outcome' => CallOutcome::class,
            'agreement' => CallAgreement::class,
            'commitment_id' => 'integer',
            'activity_id' => 'integer',
            'follow_up_on' => 'immutable_date',
            'follow_up_notified_at' => 'immutable_datetime',
            'follow_up_closed_at' => 'immutable_datetime',
        ];
    }
}
