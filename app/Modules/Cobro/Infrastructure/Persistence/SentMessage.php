<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "cobro_sent_messages": a mail Graph accepted, with the ids that
 * join replies and bounces to it. Written only by GraphMailSender.
 *
 * @property int $id
 * @property CompanyCode $company
 * @property string $mailbox
 * @property int|null $client_id
 * @property string $category
 * @property string $subject
 * @property list<string> $recipients To and Cc, lowercase.
 * @property string $internet_message_id With angle brackets.
 * @property string|null $conversation_id
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'cobro_sent_messages')]
#[Fillable(['company', 'mailbox', 'client_id', 'category', 'subject', 'recipients', 'internet_message_id', 'conversation_id', 'sent_at'])]
final class SentMessage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'company' => CompanyCode::class,
            'client_id' => 'integer',
            'recipients' => 'array',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
