<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\ClientListFilter;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /cartera/clientes (cartera.clientes.index): the clients of the user's
 * current view, searchable by name or NIT and sortable by column.
 */
final class ClientListController
{
    public function __invoke(Request $request, CarteraRepository $cartera, CurrentCompany $company): View
    {
        $filter = ClientListFilter::fromInput($request->query());
        $query = $filter->toQuery(CarbonImmutable::today(), (int) config('cartera.sin_gestion_dias'));

        $advisors = [];

        foreach ($cartera->advisors() as $advisor) {
            $advisors[$advisor->code] = $company->isGroup() ? "{$advisor->name} ({$advisor->company->value})" : $advisor->name;
        }

        return view('cartera::clientes.index', [
            'filter' => $filter,
            'clients' => $cartera->paginateClients($query, (int) config('cartera.por_pagina')),
            'statusOptions' => ClientStatus::options(),
            'advisorOptions' => $advisors,
            'isGroup' => $company->isGroup(),
            'unmanagedDays' => (int) config('cartera.sin_gestion_dias'),
        ]);
    }
}
