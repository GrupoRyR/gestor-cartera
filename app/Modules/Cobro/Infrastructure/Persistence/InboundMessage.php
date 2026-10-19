<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of "cobro_inbound_messages": a reply or bounce read from a company
 * inbox. Written by MailboxSync; the replies screen only marks it read or
 * pending.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property string $mailbox
 * @property string $graph_id
 * @property string|null $internet_message_id
 * @property string|null $conversation_id
 * @property string|null $from_address
 * @property string|null $from_name
 * @property string $subject
 * @property string $preview
 * @property string|null $body
 * @property CarbonImmutable $received_at
 * @property bool $is_bounce
 * @property string|null $bounce_reason
 * @property string|null $bounced_recipient
 * @property int|null $sent_message_id
 * @property int|null $client_id
 * @property string|null $client_name
 * @property CarbonImmutable|null $read_at
 * @property int|null $read_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SentMessage|null $sentMessage
 * @property-read User|null $reader
 */
#[Table(name: 'cobro_inbound_messages')]
#[Fillable([
    'company', 'mailbox', 'graph_id', 'internet_message_id', 'conversation_id', 'from_address', 'from_name',
    'subject', 'preview', 'body', 'received_at', 'is_bounce', 'bounce_reason', 'bounced_recipient',
    'sent_message_id', 'client_id', 'client_name', 'read_at', 'read_by',
])]
final class InboundMessage extends Model
{
    /**
     * @return BelongsTo<SentMessage, $this>
     */
    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(SentMessage::class, 'sent_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by')->withTrashed();
    }

    /**
     * Messages of these companies.
     *
     * @param  Builder<self>  $query
     * @param  list<CompanyCode>  $companies
     */
    public function scopeOfCompanies(Builder $query, array $companies): void
    {
        $query->whereIn('company', array_map(fn (CompanyCode $company): string => $company->value, $companies));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'received_at' => 'immutable_datetime',
            'is_bounce' => 'boolean',
            'sent_message_id' => 'integer',
            'client_id' => 'integer',
            'read_at' => 'immutable_datetime',
            'read_by' => 'integer',
        ];
    }
}
