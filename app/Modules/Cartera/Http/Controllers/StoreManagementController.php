<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Management\LogManagement;
use App\Modules\Cartera\Domain\Management\CallResult;
use App\Modules\Cartera\Http\Requests\StoreManagementRequest;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Http\RedirectResponse;

/**
 * POST /cartera/clientes/{client}/gestiones (cartera.gestiones.store): logs a
 * call, a note or a discount offered, and goes back to the client file.
 * A client out of the user's reach is a 404.
 */
final class StoreManagementController
{
    public function __invoke(StoreManagementRequest $request, int $client, LogManagement $management): RedirectResponse
    {
        $data = $request->validated();

        try {
            $message = match ($data['tipo']) {
                'llamada' => $this->call($management, $client, $data['llamada']),
                'nota' => $this->note($management, $client, $data['nota']),
                default => $this->discount($management, $client, $data['descuento']),
            };
        } catch (RecordNotFound) {
            abort(404);
        }

        return redirect()->route('cartera.clientes.show', $client)->with('status', $message);
    }

    /**
     * @param  array{resultado: string, detalle?: string|null}  $fields
     */
    private function call(LogManagement $management, int $client, array $fields): string
    {
        $management->call($client, CallResult::from($fields['resultado']), $fields['detalle'] ?? null);

        return __('cartera::gestion.mensajes.llamada');
    }

    /**
     * @param  array{detalle: string}  $fields
     */
    private function note(LogManagement $management, int $client, array $fields): string
    {
        $management->note($client, $fields['detalle']);

        return __('cartera::gestion.mensajes.nota');
    }

    /**
     * @param  array{valor: numeric-string|int|float, detalle?: string|null}  $fields
     */
    private function discount(LogManagement $management, int $client, array $fields): string
    {
        $management->discount($client, (float) $fields['valor'], $fields['detalle'] ?? null);

        return __('cartera::gestion.mensajes.descuento');
    }
}
