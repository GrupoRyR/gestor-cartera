<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Client\ClientFiles;
use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Asesores\Application\Whatsapp\WhatsappStatement;
use App\Modules\Core\Contracts\CarteraRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The clients of the portfolio the user may see. Every read goes through
 * CarteraRepository, which applies the user's scope: a client of another
 * advisor is not in any list, and asking for it by id finds nothing.
 */
final readonly class ClientController
{
    public function __construct(
        private CarteraRepository $cartera,
        private ClientFiles $files,
        private WhatsappStatement $whatsapp,
    ) {}

    /**
     * GET /asesores/clientes.
     */
    public function index(Request $request): View
    {
        $filter = PortfolioFilter::fromQuery($request->query());
        $query = $filter->clientQuery();
        $advisors = $this->cartera->advisors();

        return view('asesores::clientes.index', [
            'filter' => $filter,
            'clients' => $this->cartera->paginateClients($query, (int) config('asesores.per_page')),
            'totals' => $this->cartera->totals($query),
            'advisors' => $advisors,
            'showAdvisor' => count($advisors) > 1,
            'today' => CarbonImmutable::today(),
        ]);
    }

    /**
     * GET /asesores/clientes/{client}: the client file. A client outside the
     * user's scope does not exist for them: 404, the same answer as for an
     * id nobody has, so the URL reveals nothing.
     */
    public function show(int $client): View
    {
        $detail = $this->cartera->client($client) ?? abort(404);

        Gate::authorize('view', $detail);

        $file = $this->files->open($detail);
        $today = CarbonImmutable::today();

        return view('asesores::clientes.show', [
            'file' => $file,
            'whatsapp' => $this->whatsapp->share($file, $today),
            'today' => $today,
            'canManage' => Gate::allows('manage', $detail),
            'canAnnotate' => Gate::allows('annotate', $detail),
        ]);
    }
}
