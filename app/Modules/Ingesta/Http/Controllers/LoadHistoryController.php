<?php

namespace App\Modules\Ingesta\Http\Controllers;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\ErrorKeys;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;
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
 *
 * Both only cover the companies of CurrentCompany::available(): every
 * company the user may see, not only the active one. A load of another
 * company answers 404, as if it did not exist.
 */
final class LoadHistoryController
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly IngestaSink $sink,
        private readonly DailyCarteraCheck $check,
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function index(Request $request): View
    {
        // Unknown filter values are ignored, like an empty select.
        $dataset = Dataset::tryFrom($request->string('lote')->toString());
        $companies = $this->companies();
        $company = CompanyCode::tryFrom($request->string('empresa')->toString());
        $company = in_array($company, $companies, true) ? $company : null;
        $status = LoadStatus::tryFrom($request->string('estado')->toString());

        $loads = IngestaLoad::query()
            ->with('receivedBy')
            ->whereIn('company', $companies)
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
            'companies' => array_combine(
                array_map(fn (CompanyCode $code): string => $code->value, $companies),
                array_map(fn (CompanyCode $code): string => $code->label(), $companies),
            ),
            'current' => array_map(
                fn (CompanyCode $code): array => ['company' => $code, 'load' => $this->sink->latest(Dataset::Receivables, $code)],
                $this->visible($this->check->companies(), $companies),
            ),
            'missing' => $this->visible($this->check->missing($now), $companies),
            'deadline' => $this->check->deadline($now),
        ]);
    }

    public function show(IngestaLoad $load): View
    {
        abort_unless(in_array($load->company, $this->companies(), true), 404);

        $load->loadMissing('receivedBy');

        return view('ingesta::historial.show', [
            'load' => $load,
            // Not "errors": that name holds the session's validation errors in every view.
            'fieldErrors' => $load->status === LoadStatus::Rejected
                ? ErrorKeys::sort($load->errors ?? [], DatasetSchemas::for($load->dataset)->columnNames())
                : [],
            'totals' => $load->status === LoadStatus::Accepted && $load->dataset === Dataset::Receivables,
        ]);
    }

    /**
     * The companies the user may see, like ManualUploadRequest::companies().
     *
     * @return list<CompanyCode>
     */
    private function companies(): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $this->currentCompany->available());
    }

    /**
     * The codes of $codes that are also in $companies, in the order of $codes.
     *
     * @param  list<CompanyCode>  $codes
     * @param  list<CompanyCode>  $companies
     * @return list<CompanyCode>
     */
    private function visible(array $codes, array $companies): array
    {
        return array_values(array_filter($codes, fn (CompanyCode $code): bool => in_array($code, $companies, true)));
    }
}
