<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Domain\Mail\MessageIds;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the send an inbound message answers, in this order:
 *
 * 1. the same Graph conversation (replies, and Exchange's own bounces);
 * 2. for a bounce, a sent Message-ID quoted in its body (the original
 *    headers that delivery reports include);
 * 3. for a reply, the latest send to its sender address with a client,
 *    within LOOKBACK_DAYS (a client who writes a new mail instead of
 *    answering).
 *
 * Only sends from the same mailbox count. The latest matching send wins.
 */
final class InboundLinker
{
    public const int LOOKBACK_DAYS = 90;

    public function find(string $mailbox, ?string $conversationId, bool $isBounce, string $body, ?string $from): ?SentMessage
    {
        if ($conversationId !== null && $conversationId !== '') {
            $sent = $this->latest($mailbox)->where('conversation_id', $conversationId)->first();

            if ($sent !== null) {
                return $sent;
            }
        }

        if ($isBounce) {
            $ids = MessageIds::in($body);

            return $ids === [] ? null : $this->latest($mailbox)->whereIn('internet_message_id', $ids)->first();
        }

        $from = mb_strtolower(trim((string) $from));

        if ($from === '') {
            return null;
        }

        // recipients is a small JSON list: LIKE narrows the candidates (an
        // unescaped "_" only widens them) and the exact check runs here.
        return $this->latest($mailbox)
            ->whereNotNull('client_id')
            ->where('sent_at', '>=', CarbonImmutable::now()->subDays(self::LOOKBACK_DAYS))
            ->where('recipients', 'like', '%'.$from.'%')
            ->limit(50)
            ->get()
            ->first(fn (SentMessage $sent): bool => in_array($from, $sent->recipients, true));
    }

    /**
     * @return Builder<SentMessage>
     */
    private function latest(string $mailbox): Builder
    {
        return SentMessage::query()
            ->where('mailbox', $mailbox)
            ->orderByDesc('sent_at')
            ->orderByDesc('id');
    }
}
