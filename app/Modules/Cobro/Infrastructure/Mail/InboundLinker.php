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
 *    answering). A sender at one of our own domains never matches here:
 *    the recipients of a send include its Cc, where internal staff are
 *    copied, so their unrelated mail would join the latest client send.
 *    An internal reply inside the conversation still links by step 1.
 *
 * Only sends from the same mailbox count. The latest matching send wins.
 */
final class InboundLinker
{
    public const int LOOKBACK_DAYS = 90;

    /**
     * @param  list<string>  $ownDomains  Our domains (any case); a sender at one of them skips step 3.
     */
    public function find(string $mailbox, ?string $conversationId, bool $isBounce, string $body, ?string $from, array $ownDomains = []): ?SentMessage
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

        if ($from === '' || self::fromOwnDomain($from, $ownDomains)) {
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
     * Whether the domain of $from (in lower case) is exactly one of
     * $ownDomains, in any case; a subdomain does not count.
     *
     * @param  list<string>  $ownDomains
     */
    private static function fromOwnDomain(string $from, array $ownDomains): bool
    {
        $at = mb_strrpos($from, '@');
        $domain = $at === false ? '' : mb_substr($from, $at + 1);

        if ($domain === '') {
            return false;
        }

        foreach ($ownDomains as $own) {
            if (mb_strtolower(trim($own)) === $domain) {
                return true;
            }
        }

        return false;
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
