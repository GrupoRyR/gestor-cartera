<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Calls\LogCall;
use App\Modules\Cartera\Http\Requests\StoreCallRequest;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * POST /cartera/clientes/{client}/llamadas (cartera.llamadas.store): logs a
 * call from the client file and goes back to it.
 *
 * LogCall decides: an agreement the user may not make is a 403, a client out
 * of the user's reach a 404, and its validation errors (ocurrio, seguimiento,
 * acuerdo, facturas) go back to the call form, nested under "llamada" like
 * its fields, with what was typed.
 */
final class CallController
{
    public function __invoke(StoreCallRequest $request, int $client, LogCall $calls): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        try {
            $calls->log($request->toNewCall($client), $user);
        } catch (AuthorizationException) {
            abort(403);
        } catch (RecordNotFound) {
            abort(404);
        } catch (ValidationException $e) {
            return redirect()->route('cartera.clientes.show', $client)
                ->withInput()
                ->withErrors($this->nested($e->errors()));
        }

        return redirect()->route('cartera.clientes.show', $client)->with('status', __('cartera::llamadas.mensajes.registrada'));
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, list<string>>
     */
    private function nested(array $errors): array
    {
        $nested = [];

        foreach ($errors as $field => $messages) {
            $nested["llamada.{$field}"] = $messages;
        }

        return $nested;
    }
}
