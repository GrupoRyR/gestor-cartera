<?php

namespace App\Modules\Cobro\Infrastructure\Reports;

use App\Modules\Cobro\Application\Reports\MailReportFilter;
use App\Modules\Cobro\Application\Reports\MailReportMetrics;
use App\Modules\Cobro\Application\Reports\ReportNumbers;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Mail\ReplySender;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The collection report (GET /cobro/informe): the items of the weekly
 * collection and circularization runs scheduled in the filter's days, and
 * their figures. Cobro does not keep the client's advisor, so it asks
 * Core's CarteraRepository: for the advisor filter, the advisor's clients
 * of that company, once per instance (one request) however many queries
 * use them; for the advisor column, each client of the page (at most a
 * page of keyed reads, cheaper than a pass over every client) or, in the
 * export, every client of each company in one pass.
 */
final class MailReport
{
    /** Escape character of the client search, named in the SQL: SQLite has none by default. */
    private const string LIKE_ESCAPE = '!';

    /**
     * Client ids of each advisor already read, by MailReportFilter::advisorKey().
     *
     * @var array<string, list<int>>
     */
    private array $advisorClients = [];

    public function __construct(
        private readonly CarteraRepository $cartera,
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

    public function metrics(MailReportFilter $filter): MailReportMetrics
    {
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
        );
    }

    /**
     * The current advisor's name of each client of $items (client id =>
     * name), as Core knows it; clients it does not know are left out.
     *
     * @param  iterable<RunItem>  $items
     * @return array<int, string>
     */
    public function advisorNames(iterable $items): array
    {
        $names = [];

        foreach ($items as $item) {
            if (! array_key_exists($item->client_id, $names)) {
                $names[$item->client_id] = $this->cartera->client($item->client_id)?->summary->advisor?->name;
            }
        }

        return array_filter($names, fn (?string $name): bool => $name !== null);
    }

    /**
     * The current advisor's name of every client of the filter's companies
     * (client id => name), with one pass over the clients of each company:
     * for the export, where asking client by client would take a call per
     * row. With the advisor filter, only the clients of its company.
     *
     * @return array<int, string>
     */
    public function advisorNamesByClient(MailReportFilter $filter): array
    {
        $companies = $filter->advisorCompany !== null && $filter->advisorCode !== null ? [$filter->advisorCompany] : $filter->companies;
        $names = [];

        foreach ($companies as $company) {
            foreach ($this->cartera->allClients(new ClientQuery(companies: [$company])) as $client) {
                if ($client->advisor !== null) {
                    $names[$client->id] = $client->advisor->name;
                }
            }
        }

        return $names;
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
                ->where('cobro_runs.company', $filter->advisorCompany?->value)
                ->whereIn('cobro_run_items.client_id', $this->advisorClients($filter->advisorCompany, (int) $filter->advisorCode)))
            ->when($filter->replied !== null, fn (Builder $query) => ItemReplies::whereReplied($query, (bool) $filter->replied));
    }

    /**
     * Ids of the clients of the advisor $code of $company, read from Core
     * the first time only.
     *
     * @return list<int>
     */
    private function advisorClients(?CompanyCode $company, int $code): array
    {
        if ($company === null) {
            return [];
        }

        $key = MailReportFilter::advisorKey($company, $code);

        if (! array_key_exists($key, $this->advisorClients)) {
            $ids = [];

            foreach ($this->cartera->allClients(new ClientQuery(advisorCode: $code, companies: [$company])) as $client) {
                $ids[] = $client->id;
            }

            $this->advisorClients[$key] = $ids;
        }

        return $this->advisorClients[$key];
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
