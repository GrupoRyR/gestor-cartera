<?php

namespace App\Modules\Cobro\Infrastructure\Reports;

use App\Modules\Cobro\Application\Reports\CommitmentWindow;
use App\Modules\Cobro\Application\Reports\MailReportFilter;
use App\Modules\Cobro\Application\Reports\MailReportMetrics;
use App\Modules\Cobro\Application\Reports\ReportNumbers;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Mail\ReplySender;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The collection report (GET /cobro/informe): the items of the weekly
 * collection and circularization runs scheduled in the filter's days, and
 * their figures. The client's advisor and group come from Core through
 * ReportClients and reach the queries as MailReportFilter::$clientIds;
 * the payment commitments after each mail, from CompromisoService, read
 * once for every mailed client of the filter (commitmentWindow()).
 */
final class MailReport
{
    /** Escape character of the client search, named in the SQL: SQLite has none by default. */
    private const string LIKE_ESCAPE = '!';

    public function __construct(
        private readonly CompromisoService $compromisos,
    ) {}

    /**
     * One page of items, newest run first, each with its run and its first
     * reply (first_reply_at, null without replies).
     *
     * @return LengthAwarePaginator<int, RunItem>
     */
    public function items(MailReportFilter $filter, int $perPage): LengthAwarePaginator
    {
        return $this->matching($filter)
            ->select('cobro_run_items.*')
            ->selectSub(ItemReplies::firstReceivedAt(), 'first_reply_at')
            ->withCasts(['first_reply_at' => 'immutable_datetime'])
            ->with('run')
            ->orderByDesc('cobro_runs.scheduled_for')
            ->orderByDesc('cobro_run_items.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Every item of the filter, for the export: run by run, newest first,
     * and inside a run newest item first, read $chunk items at a time (by
     * id, not by offset) so memory stays flat however many there are. Each
     * item carries its run and first_reply_at, as in items().
     *
     * @return iterable<int, RunItem>
     */
    public function allItems(MailReportFilter $filter, int $chunk = 500): iterable
    {
        $runs = Run::query()
            ->whereIn('id', $this->matching($filter)->select('cobro_run_items.run_id'))
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->get();

        foreach ($runs as $run) {
            $items = $this->matching($filter)
                ->where('cobro_run_items.run_id', $run->id)
                ->select('cobro_run_items.*')
                ->selectSub(ItemReplies::firstReceivedAt(), 'first_reply_at')
                ->withCasts(['first_reply_at' => 'immutable_datetime'])
                ->lazyByIdDesc($chunk, 'cobro_run_items.id', 'id');

            foreach ($items as $item) {
                yield $item->setRelation('run', $run);
            }
        }
    }

    /**
     * The commitments every mailed client of the filter registered from the
     * start of its first day to CommitmentWindow::DAYS days after the end of
     * its last, with one CompromisoService call: enough for the window of
     * every mail in the filter.
     */
    public function commitmentWindow(MailReportFilter $filter): CommitmentWindow
    {
        $clientIds = $this->matching($filter)
            ->whereIn('cobro_run_items.status', ItemReplies::MAILED)
            ->distinct()
            ->pluck('cobro_run_items.client_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($clientIds === []) {
            return new CommitmentWindow;
        }

        [$from, $to] = $this->range($filter);

        return new CommitmentWindow($this->compromisos->createdBetween($clientIds, $from, $to->addDays(CommitmentWindow::DAYS)));
    }

    /**
     * Whether the item's mail reached the mailbox, so its client's
     * commitments after the send count (CommitmentWindow).
     */
    public static function mailed(RunItem $item): bool
    {
        return in_array($item->status, ItemReplies::MAILED, true) && $item->sent_at !== null;
    }

    public function metrics(MailReportFilter $filter, ?CommitmentWindow $commitments = null): MailReportMetrics
    {
        $commitments ??= $this->commitmentWindow($filter);
        /** @var array<string, int> $counts */
        $counts = $this->matching($filter)
            ->toBase()
            ->selectRaw('cobro_run_items.status, count(*) as total')
            ->groupBy('cobro_run_items.status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $clients = $this->matching($filter)
            ->whereIn('cobro_run_items.status', ItemReplies::MAILED)
            ->distinct()
            ->count('cobro_run_items.client_id');

        // Dates come back as text in the app timezone, the same on MySQL
        // and SQLite; the difference is taken here, not in SQL.
        $timezone = (string) config('app.timezone');
        $firstReplies = $this->matching($filter)
            ->tap(fn (Builder $items) => ItemReplies::whereReplied($items))
            ->toBase()
            ->select('cobro_run_items.sent_at')
            ->selectSub(ItemReplies::firstReceivedAt(), 'first_reply_at')
            ->get()
            ->map(fn (object $row): ?float => isset($row->sent_at, $row->first_reply_at)
                ? (float) max(0, CarbonImmutable::parse((string) $row->sent_at, $timezone)->diffInSeconds(CarbonImmutable::parse((string) $row->first_reply_at, $timezone)))
                : null);

        $committed = $this->matching($filter)
            ->whereIn('cobro_run_items.status', ItemReplies::MAILED)
            ->whereNotNull('cobro_run_items.sent_at')
            ->toBase()
            ->select(['cobro_run_items.client_id', 'cobro_run_items.sent_at'])
            ->get()
            ->filter(fn (object $row): bool => $commitments->first((int) $row->client_id, CarbonImmutable::parse((string) $row->sent_at, $timezone)) !== null)
            ->count();

        $bounced = $counts[ItemStatus::Bounced->value] ?? 0;

        return new MailReportMetrics(
            sent: ($counts[ItemStatus::Sent->value] ?? 0) + $bounced,
            failed: $counts[ItemStatus::Failed->value] ?? 0,
            bounced: $bounced,
            clients: $clients,
            replies: $firstReplies->count(),
            medianFirstReply: ReportNumbers::median($firstReplies->filter(fn (?float $seconds): bool => $seconds !== null)->values()->all()),
            teamReplies: SentMessage::query()
                ->where('category', ReplySender::CATEGORY)
                ->whereIn('company', $this->companyValues($filter->companies))
                ->whereBetween('sent_at', $this->range($filter))
                ->count(),
            committed: $committed,
        );
    }

    /**
     * The items of the filter, joined to their run (cobro_runs columns are
     * qualified), without a select.
     *
     * @return Builder<RunItem>
     */
    private function matching(MailReportFilter $filter): Builder
    {
        return RunItem::query()
            ->join('cobro_runs', 'cobro_runs.id', '=', 'cobro_run_items.run_id')
            ->whereIn('cobro_runs.company', $this->companyValues($filter->companies))
            ->whereBetween('cobro_runs.scheduled_for', $this->range($filter))
            ->when($filter->kind !== null, fn (Builder $query) => $query->where('cobro_runs.kind', $filter->kind?->value))
            ->when($filter->status !== null, fn (Builder $query) => $query->where('cobro_run_items.status', $filter->status?->value))
            ->when($filter->client !== null, function (Builder $query) use ($filter) {
                $pattern = '%'.str_replace(
                    [self::LIKE_ESCAPE, '%', '_'],
                    [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
                    (string) $filter->client,
                ).'%';
                $like = fn (string $column): string => "lower({$column}) like lower(?) escape '".self::LIKE_ESCAPE."'";

                $query->where(fn (Builder $text) => $text
                    ->whereRaw($like('cobro_run_items.client_code'), [$pattern])
                    ->orWhereRaw($like('cobro_run_items.client_name'), [$pattern])
                    ->orWhereRaw($like('cobro_run_items.tax_id'), [$pattern]));
            })
            ->when($filter->advisorCompany !== null && $filter->advisorCode !== null, fn (Builder $query) => $query
                ->where('cobro_runs.company', $filter->advisorCompany?->value))
            ->when($filter->clientIds !== null, fn (Builder $query) => $query->whereIn('cobro_run_items.client_id', $filter->clientIds ?? []))
            ->when($filter->replied !== null, fn (Builder $query) => ItemReplies::whereReplied($query, (bool) $filter->replied));
    }

    /**
     * The filter's days from the start of the first to the end of the last
     * in Bogotá, in the app timezone the dates are stored in.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function range(MailReportFilter $filter): array
    {
        $timezone = (string) config('app.timezone');

        return [
            CarbonImmutable::parse($filter->from->toDateString(), CobroServiceProvider::TIMEZONE)->startOfDay()->setTimezone($timezone),
            CarbonImmutable::parse($filter->to->toDateString(), CobroServiceProvider::TIMEZONE)->endOfDay()->setTimezone($timezone),
        ];
    }

    /**
     * @param  list<CompanyCode>  $companies
     * @return list<string>
     */
    private function companyValues(array $companies): array
    {
        return array_map(fn (CompanyCode $company): string => $company->value, $companies);
    }
}
