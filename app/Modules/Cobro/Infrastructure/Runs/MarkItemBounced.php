<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;

/**
 * Listens to BounceLinked: the run item whose mail bounced becomes
 * rebotado, with the reason and the failed address read from the bounce.
 */
final class MarkItemBounced
{
    public function handle(BounceLinked $event): void
    {
        $bounce = $event->bounce;

        if ($bounce->sent_message_id === null) {
            return;
        }

        $messageId = SentMessage::query()->whereKey($bounce->sent_message_id)->value('internet_message_id');

        RunItem::query()
            ->where(fn ($query) => $query
                ->where('sent_message_id', $bounce->sent_message_id)
                ->when(is_string($messageId), fn ($byId) => $byId->orWhere('internet_message_id', $messageId)))
            ->where('status', ItemStatus::Sent)
            ->update([
                'status' => ItemStatus::Bounced,
                'bounce_reason' => $bounce->bounce_reason,
                'bounced_recipient' => $bounce->bounced_recipient,
                'bounced_at' => $bounce->received_at,
            ]);
    }
}
