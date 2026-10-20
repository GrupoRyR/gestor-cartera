<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Mail\BounceLinked;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use Illuminate\Support\Facades\Log;

/**
 * Listens to BounceLinked: the run item whose mail bounced becomes
 * rebotado, with the reason and the failed address read from the bounce.
 *
 * Only a failure of one of the item's own recipients (To), or of an
 * address the report does not name, counts: when a carbon copy bounced
 * the client still got the mail, so the item stays enviado and the
 * address goes to the log.
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

        $items = RunItem::query()
            ->where(fn ($query) => $query
                ->where('sent_message_id', $bounce->sent_message_id)
                ->when(is_string($messageId), fn ($byId) => $byId->orWhere('internet_message_id', $messageId)))
            ->where('status', ItemStatus::Sent)
            ->get();

        foreach ($items as $item) {
            if (! self::failedForClient($item, $bounce->bounced_recipient)) {
                Log::warning('Cobro: a carbon copy of a collection mail bounced; the client mail stays sent.', [
                    'run_item' => $item->id,
                    'address' => $bounce->bounced_recipient,
                ]);

                continue;
            }

            RunItem::query()->whereKey($item->id)->where('status', ItemStatus::Sent)->update([
                'status' => ItemStatus::Bounced,
                'bounce_reason' => $bounce->bounce_reason,
                'bounced_recipient' => $bounce->bounced_recipient,
                'bounced_at' => $bounce->received_at,
            ]);
        }
    }

    /**
     * Whether the failed address is unknown or one of the item's To
     * recipients.
     */
    private static function failedForClient(RunItem $item, ?string $failed): bool
    {
        if ($failed === null || trim($failed) === '') {
            return true;
        }

        $to = array_map(fn (string $address): string => mb_strtolower(trim($address)), $item->recipients);

        return in_array(mb_strtolower(trim($failed)), $to, true);
    }
}
