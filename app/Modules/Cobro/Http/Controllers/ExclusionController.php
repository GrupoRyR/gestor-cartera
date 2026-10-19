<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Http\Requests\ExcludeClientsRequest;
use App\Modules\Cobro\Infrastructure\Exclusions\ExclusionList;
use App\Modules\Cobro\Infrastructure\Persistence\Exclusion;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The exclusion list screen (cobro.exclusiones.*):
 *
 * - GET /cobro/exclusiones: the clients of the user's scope with overdue
 *   balance (all of them with ?todos=1), searchable by name or NIT, each
 *   with a checkbox; and the clients already excluded, with theirs.
 * - POST /cobro/exclusiones: excludes the marked clients.
 * - POST /cobro/exclusiones/quitar: removes the marked exclusions.
 *
 * Clients come from CarteraRepository, so the scope rule applies: a client
 * the user cannot see is never excluded, and only exclusions of the
 * companies in CurrentCompany::scope() are listed or removed.
 */
final class ExclusionController
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly CarteraRepository $cartera,
        private readonly CurrentCompany $currentCompany,
        private readonly ExclusionList $exclusions,
    ) {}

    public function index(Request $request): View
    {
        $search = trim($request->string('buscar')->toString());
        $all = $request->boolean('todos');

        $clients = $this->cartera->paginateClients(new ClientQuery(
            search: $search === '' ? null : $search,
            onlyOverdue: ! $all,
            sort: 'overdue',
            direction: 'desc',
        ), self::PER_PAGE);

        /** @var list<ClientSummary> $items */
        $items = $clients->items();
        $excludedOnPage = Exclusion::query()
            ->whereIn('client_id', array_map(fn (ClientSummary $client): int => $client->id, $items))
            ->get()
            ->keyBy('client_id');

        $excluded = Exclusion::query()
            ->with('creator')
            ->whereIn('company', $this->companyValues())
            ->orderBy('client_name')
            ->orderBy('id')
            ->paginate(50, pageName: 'excluidos')
            ->withQueryString();

        return view('cobro::exclusiones.index', [
            'clients' => $clients,
            'excludedOnPage' => $excludedOnPage,
            'excluded' => $excluded,
            'search' => $search,
            'all' => $all,
            'isGroup' => $this->currentCompany->isGroup(),
            'today' => CarbonImmutable::now(CobroServiceProvider::TIMEZONE)->startOfDay(),
        ]);
    }

    public function store(ExcludeClientsRequest $request): RedirectResponse
    {
        $clients = [];

        foreach ($request->clientIds() as $id) {
            $client = $this->cartera->client($id);

            if ($client !== null) {
                $clients[] = $client->summary;
            }
        }

        if ($clients === []) {
            return back()->with('error', __('cobro::exclusiones.mensajes.sin_clientes'));
        }

        $count = $this->exclusions->exclude(
            $clients,
            (string) $request->validated('motivo'),
            $request->expiresOn(),
            ((int) $request->user()?->getAuthIdentifier()) ?: null,
        );

        return back()->with('status', trans_choice('cobro::exclusiones.mensajes.excluidos', $count, ['total' => $count]));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate(
            ['exclusiones' => ['required', 'array', 'min:1', 'max:500'], 'exclusiones.*' => ['integer', 'min:1']],
            ['exclusiones.required' => __('cobro::exclusiones.validacion.marca_uno'), 'exclusiones.min' => __('cobro::exclusiones.validacion.marca_uno')],
            ['exclusiones' => __('cobro::exclusiones.validacion.exclusiones')],
        );

        $count = $this->exclusions->remove(
            array_values(array_map('intval', (array) $validated['exclusiones'])),
            array_map(fn (Company $company): CompanyCode => $company->code, $this->currentCompany->scope()),
        );

        return back()->with('status', trans_choice('cobro::exclusiones.mensajes.quitados', $count, ['total' => $count]));
    }

    /**
     * @return list<string>
     */
    private function companyValues(): array
    {
        return array_map(fn (Company $company): string => $company->code->value, $this->currentCompany->scope());
    }
}
