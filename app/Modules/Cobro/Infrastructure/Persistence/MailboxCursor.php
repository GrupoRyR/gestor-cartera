<?php

namespace App\Modules\Cobro\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A row of "cobro_mailbox_cursors": where the delta synchronization of one
 * company inbox stands. The links hold Graph state tokens, not secrets.
 *
 * @property int $id
 * @property string $mailbox
 * @property string|null $delta_link
 * @property string|null $next_link
 * @property CarbonImmutable|null $last_synced_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Table(name: 'cobro_mailbox_cursors')]
#[Fillable(['mailbox', 'delta_link', 'next_link', 'last_synced_at', 'last_error'])]
final class MailboxCursor extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'immutable_datetime',
        ];
    }
}
