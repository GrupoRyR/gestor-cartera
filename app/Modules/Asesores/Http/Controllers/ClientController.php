<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Portfolio\PortfolioFilter;
use App\Modules\Core\Contracts\CarteraRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The clients of the portfolio the user may see. Every read goes through
 * CarteraRepository, which applies the user's scope: a client of another
 * advisor is not in any list, and asking for it by id finds nothing.
 */
final readonly class ClientController
{
    public function __construct(private CarteraRepository $cartera) {}

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
}
