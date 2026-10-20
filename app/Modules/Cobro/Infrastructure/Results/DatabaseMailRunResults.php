<?php

namespace App\Modules\Cobro\Infrastructure\Results;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;

/**
 * The run results from the delivery report tables: cobro_runs,
 * cobro_run_items and the replies read into cobro_inbound_messages.
 *
 * A client counts as sent while its item is enviado or rebotado (the
 * mailbox accepted the mail; MarkItemBounced turns it rebotado only when
 * one of its own recipients failed); failed counts the items fallido. A
 * client answered when a message that is not a bounce is linked to the
 * item's send, found by its id or, failing that, by its Message-ID. Three
 * queries, whatever the number of runs.
 */
final class DatabaseMailRunResults implements MailRunResultsProvider
{
    public function results(CompanyCode $company, MailRunKind $kind, CarbonImmutable $from, CarbonImmutable $to): MailRunResults
    {
        $firstDay = self::day($from);
        $lastDay = self::day($to);

        if ($firstDay->greaterThan($lastDay)) {
            throw new InvalidArgumentException("The period ends ({$to->toDateString()}) before it starts ({$from->toDateString()}).");
        }

        // scheduled_for is stored in the app timezone (RunPlanner::start()).
        $timezone = (string) config('app.timezone');
        $runs = Run::query()
            ->where('company', $company)
            ->where('kind', RunKind::from($kind->value))
            ->whereBetween('scheduled_for', [
                $firstDay->startOfDay()->setTimezone($timezone),
                $lastDay->endOfDay()->setTimezone($timezone),
            ]);

        $runCount = (clone $runs)->count();

        if ($runCount === 0) {
            return MailRunResults::empty($company, $kind, $firstDay, $lastDay);
        }

        $runIds = (clone $runs)->select('id');

        /** @var array<string, int> $counts */
        $counts = RunItem::query()
            ->toBase()
            ->whereIn('run_id', $runIds)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        // An item normally links its send through sent_message_id;
        // SendRunItem fills it after marking the item sent, so when that
        // update failed only the Message-ID is left (MarkItemBounced falls
        // back the same way).
        $replies = RunItem::query()
            ->whereIn('run_id', $runIds)
            ->whereIn('status', [ItemStatus::Sent, ItemStatus::Bounced])
            ->whereExists(fn (QueryBuilder $inbound) => $inbound
                ->selectRaw('1')
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
                            ->whereColumn('cobro_sent_messages.internet_message_id', 'cobro_run_items.internet_message_id')))))
            ->count();

        $bounced = $counts[ItemStatus::Bounced->value] ?? 0;

        return new MailRunResults(
            company: $company,
            kind: $kind,
            from: $firstDay,
            to: $lastDay,
            runs: $runCount,
            sent: ($counts[ItemStatus::Sent->value] ?? 0) + $bounced,
            bounced: $bounced,
            withoutEmail: $counts[ItemStatus::NoEmail->value] ?? 0,
            failed: $counts[ItemStatus::Failed->value] ?? 0,
            replies: $replies,
        );
    }

    /**
     * The calendar day of $date, at its start in Bogotá.
     */
    private static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), CobroServiceProvider::TIMEZONE)->startOfDay();
    }
}
