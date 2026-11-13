<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Clients\ClientListFilter;
use App\Modules\Cartera\Http\Requests\AssignManagersRequest;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Sets the portfolio manager ("gestor") of clients through Core's
 * ClientAssignmentService, which checks core.clients.assign, writes the log
 * entry and audits each change. A user the service refuses gets a 403.
 *
 * POST /cartera/clientes/gestor (cartera.clientes.asignar): the chosen
 * clients of the list, or every client that matches its filters, capped at
 * cartera.assignment.max_bulk. The cap is checked on a count taken before
 * the assignment, so clients that start matching in between can pass it.
 */
final class AssignManagerController
{
    public function bulk(AssignManagersRequest $request, ClientAssignmentService $assignments, CarteraRepository $cartera): RedirectResponse
    {
        $managerUserId = $request->managerUserId();

        try {
            if ($request->allMatching()) {
                $query = ClientListFilter::forUser($request->filters(), $request->user())
                    ->toQuery(CarbonImmutable::today(), (int) config('cartera.sin_gestion_dias'));
                $matching = $cartera->paginateClients($query, 1)->total();
                $max = (int) config('cartera.assignment.max_bulk');

                // Every change writes a log entry and an audit row in this request.
                if ($matching > $max) {
                    return back()->with('error', __('cartera::clientes.asignar.errores.demasiados', [
                        'count' => self::number($matching),
                        'max' => self::number($max),
                    ]));
                }

                $changed = $assignments->assignMatching($query, $managerUserId);
            } else {
                $changed = $assignments->assign($request->clientIds(), $managerUserId);
            }
        } catch (InvalidArgumentException) {
            return back()->with('error', __('cartera::clientes.asignar.errores.no_trabaja'));
        } catch (RecordNotFound) {
            return back()->with('error', __('cartera::clientes.asignar.errores.no_disponible'));
        }

        return back()->with('status', trans_choice('cartera::clientes.asignar.hecho', $changed, ['count' => self::number($changed)]));
    }

    private static function number(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}
