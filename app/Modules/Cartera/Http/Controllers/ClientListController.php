<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\AssigneeOptions;
use App\Modules\Cartera\Application\Clients\ClientListFilter;
use App\Modules\Cartera\Application\Clients\ManagerFilter;
use App\Modules\Cartera\Domain\Clients\ChoosableStatuses;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /cartera/clientes (cartera.clientes.index): the clients of the user's
 * current view, searchable by name or NIT and sortable by column. The
 * "Gestor" filter opens on "Mi cartera" for the analyst and on the whole
 * portfolio for everyone else (ManagerFilter::defaultFor()). Whoever may
 * assign managers (core.clients.assign) also gets the bulk "Asignar gestor"
 * form; $assigneeOptions is null for everyone else.
 */
final class ClientListController
{
    public function __invoke(Request $request, CarteraRepository $cartera, CurrentCompany $company, ClientAssignmentService $assignments): View
    {
        $filter = ClientListFilter::forUser($request->query(), $request->user());
        $query = $filter->toQuery(CarbonImmutable::today(), (int) config('cartera.sin_gestion_dias'));

        $advisors = [];

        foreach ($cartera->advisors() as $advisor) {
            $advisors[$advisor->code] = $company->isGroup() ? "{$advisor->name} ({$advisor->company->value})" : $advisor->name;
        }

        return view('cartera::clientes.index', [
            'filter' => $filter,
            'clients' => $cartera->paginateClients($query, (int) config('cartera.por_pagina')),
            'statusOptions' => ChoosableStatuses::options(),
            'managerOptions' => ManagerFilter::options(),
            'advisorOptions' => $advisors,
            'isGroup' => $company->isGroup(),
            'unmanagedDays' => (int) config('cartera.sin_gestion_dias'),
            'assigneeOptions' => $request->user()?->can('core.clients.assign') ? AssigneeOptions::from($assignments->assignees()) : null,
            'maxBulk' => (int) config('cartera.assignment.max_bulk'),
        ]);
    }
}
