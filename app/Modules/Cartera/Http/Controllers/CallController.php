<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Calls\LogCall;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Http\Requests\StoreCallRequest;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
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
 *
 * A call agreed as a payment commitment goes on to the "Nuevo compromiso"
 * form, with the balance of the call's invoices as the value and the call's
 * id, so the commitment saved there links back to it (AttachCommitment).
 */
final class CallController
{
    public function __invoke(StoreCallRequest $request, int $client, LogCall $calls): RedirectResponse
    {
        $user = $request->user() ?? abort(403);

        try {
            $call = $calls->log($request->toNewCall($client), $user);
        } catch (AuthorizationException) {
            abort(403);
        } catch (RecordNotFound) {
            abort(404);
        } catch (ValidationException $e) {
            return redirect()->route('cartera.clientes.show', $client)
                ->withInput()
                ->withErrors($this->nested($e->errors()));
        }

        if ($call->agreement === CallAgreement::PaymentCommitment) {
            return redirect()->route('cartera.clientes.show', $this->commitmentForm($call))
                ->with('status', __('cartera::llamadas.mensajes.registrada_compromiso'));
        }

        return redirect()->route('cartera.clientes.show', $client)->with('status', __('cartera::llamadas.mensajes.registrada'));
    }

    /**
     * The client file with the "Nuevo compromiso" form open: ?valor= is the
     * sum of the balances of the call's invoices, added in cents and written
     * with two decimals as SuggestedAmount reads it, left out when there is
     * nothing to suggest.
     *
     * @return array<string, int|string>
     */
    private function commitmentForm(CallRecord $call): array
    {
        $cents = 0;

        foreach ($call->invoices()->pluck('balance') as $balance) {
            $cents += (int) round((float) $balance * 100);
        }

        return [
            'client' => $call->client_id,
            'nuevo-compromiso' => 1,
            ...($cents > 0 ? ['valor' => number_format($cents / 100, 2, '.', '')] : []),
            'llamada' => $call->id,
        ];
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
