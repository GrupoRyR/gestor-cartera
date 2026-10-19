<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The delivery report (informe de entrega), cobro.lotes.*:
 *
 * - GET /cobro/lotes?tipo=cobro_semanal: the runs of the companies in
 *   CurrentCompany::scope(), newest first, with how many clients were
 *   sent, bounced, without e-mail, excluded and failed;
 * - GET /cobro/lotes/{run}?estado=rebotado: one run, client by client,
 *   with the bounce reason read from the mailbox. A run of another company
 *   answers 404.
 */
final class RunReportController
{
    public const int PER_PAGE = 25;

    /**
     * The statuses the report counts, in the order it shows them.
     *
     * @var list<ItemStatus>
     */
    public const array REPORTED = [
        ItemStatus::Sent, ItemStatus::Bounced, ItemStatus::NoEmail, ItemStatus::Excluded,
        ItemStatus::Failed, ItemStatus::Skipped, ItemStatus::Pending, ItemStatus::Sending,
    ];

    public function __construct(
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function index(Request $request): View
    {
        $kind = RunKind::tryFrom($request->string('tipo')->toString());
        $counts = [];

        foreach (self::REPORTED as $status) {
            $counts["items as {$status->value}_count"] = fn (Builder $items) => $items->where('status', $status);
        }

        $runs = Run::query()
            ->whereIn('company', $this->companyValues())
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->withCount($counts)
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('cobro::lotes.index', [
            'runs' => $runs,
            'kind' => $kind,
            'isGroup' => $this->currentCompany->isGroup(),
        ]);
    }

    public function show(Request $request, Run $run): View
    {
        abort_unless(in_array($run->company->value, $this->companyValues(), true), 404);
        $run->loadMissing('trigger');

        $filter = ItemStatus::tryFrom($request->string('estado')->toString());

        /** @var array<string, int> $counts */
        $counts = RunItem::query()
            ->where('run_id', $run->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();

        $items = RunItem::query()
            ->where('run_id', $run->id)
            ->when($filter !== null, fn (Builder $query) => $query->where('status', $filter))
            ->orderBy('client_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('cobro::lotes.show', [
            'run' => $run,
            'items' => $items,
            'filter' => $filter,
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    /**
     * @return list<string>
     */
    private function companyValues(): array
    {
        return array_map(fn (Company $company): string => $company->code->value, $this->currentCompany->scope());
    }
}
