<?php

namespace App\Modules\Ingesta\Http\Controllers;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Infrastructure\DailyCarteraCheck;
use App\Modules\Ingesta\Infrastructure\Persistence\IngestaLoad;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * GET /ingesta (ingesta.index): the load history, newest received first,
 * filtered by dataset, company and status, with the current cartera of
 * each company the robot must publish daily and a warning when today's
 * robot load is missing after the deadline.
 *
 * GET /ingesta/cargas/{load} (ingesta.cargas.show): one load, with its
 * errors when rejected and its totals when it is an accepted cartera.
 */
final class LoadHistoryController
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly IngestaSink $sink,
        private readonly DailyCarteraCheck $check,
    ) {}

    public function index(Request $request): View
    {
        // Unknown filter values are ignored, like an empty select.
        $dataset = Dataset::tryFrom($request->string('lote')->toString());
        $company = CompanyCode::tryFrom($request->string('empresa')->toString());
        $status = LoadStatus::tryFrom($request->string('estado')->toString());

        $loads = IngestaLoad::query()
            ->with('receivedBy')
            ->when($dataset !== null, fn (Builder $query) => $query->where('dataset', $dataset))
            ->when($company !== null, fn (Builder $query) => $query->where('company', $company))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $now = CarbonImmutable::now();

        return view('ingesta::historial.index', [
            'loads' => $loads,
            'filters' => [
                'lote' => $dataset?->value,
                'empresa' => $company?->value,
                'estado' => $status?->value,
            ],
            'filtered' => $dataset !== null || $company !== null || $status !== null,
            'current' => array_map(
                fn (CompanyCode $code): array => ['company' => $code, 'load' => $this->sink->latest(Dataset::Receivables, $code)],
                $this->check->companies(),
            ),
            'missing' => $this->check->missing($now),
            'deadline' => $this->check->deadline($now),
        ]);
    }

    public function show(IngestaLoad $load): View
    {
        $load->loadMissing('receivedBy');

        return view('ingesta::historial.show', [
            'load' => $load,
            // Not "errors": that name holds the session's validation errors in every view.
            'fieldErrors' => $load->status === LoadStatus::Rejected ? ($load->errors ?? []) : [],
            'totals' => $load->status === LoadStatus::Accepted && $load->dataset === Dataset::Receivables,
        ]);
    }
}
