<?php

namespace App\Modules\Cobro\Infrastructure\Reports;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * When a run item counts as answered: its mail reached the mailbox
 * (enviado or rebotado) and a message that is not a bounce is linked to its
 * send. The one rule for DatabaseMailRunResults and the collection report.
 *
 * An item normally links its send through sent_message_id; SendRunItem
 * fills it after marking the item sent, so when that update failed only the
 * Message-ID is left (MarkItemBounced falls back the same way).
 */
final class ItemReplies
{
    /**
     * Statuses whose mail the mailbox accepted.
     *
     * @var list<ItemStatus>
     */
    public const array MAILED = [ItemStatus::Sent, ItemStatus::Bounced];

    /**
     * Keeps the mailed items of a cobro_run_items query that were answered,
     * or, with $replied false, the mailed items that were not.
     *
     * @param  Builder<*>|QueryBuilder  $items
     */
    public static function whereReplied(Builder|QueryBuilder $items, bool $replied = true): void
    {
        $items->whereIn('cobro_run_items.status', self::MAILED);
        $replies = fn (QueryBuilder $inbound) => self::linkedTo($inbound->selectRaw('1'));

        if ($replied) {
            $items->whereExists($replies);
        } else {
            $items->whereNotExists($replies);
        }
    }

    /**
     * Subquery for selectSub(): when the outer item got its first reply
     * (null without replies).
     *
     * @return Closure(QueryBuilder): QueryBuilder
     */
    public static function firstReceivedAt(): Closure
    {
        return fn (QueryBuilder $inbound): QueryBuilder => self::linkedTo($inbound->selectRaw('min(cobro_inbound_messages.received_at)'));
    }

    /**
     * The replies, not bounces, linked to the send of the outer
     * cobro_run_items row: by its id or, failing that, by its Message-ID.
     */
    private static function linkedTo(QueryBuilder $inbound): QueryBuilder
    {
        return $inbound
            ->from('cobro_inbound_messages')
            ->where('cobro_inbound_messages.is_bounce', false)
            ->where(fn (QueryBuilder $link) => $link
                ->whereColumn('cobro_inbound_messages.sent_message_id', 'cobro_run_items.sent_message_id')
                ->orWhere(fn (QueryBuilder $byMessageId) => $byMessageId
                    ->whereNull('cobro_run_items.sent_message_id')
                    ->whereNotNull('cobro_run_items.internet_message_id')
                    ->whereIn('cobro_inbound_messages.sent_message_id', fn (QueryBuilder $sends) => $sends
                        ->select('cobro_sent_messages.id')
                        ->from('cobro_sent_messages')
                        ->whereColumn('cobro_sent_messages.internet_message_id', 'cobro_run_items.internet_message_id'))));
    }
}
