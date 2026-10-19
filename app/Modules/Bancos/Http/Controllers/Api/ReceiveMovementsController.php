<?php

namespace App\Modules\Bancos\Http\Controllers\Api;

use App\Modules\Bancos\Application\Import\ReceiveMovements;
use App\Modules\Bancos\Http\Requests\ReceiveMovementsRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/movimientos: Treasury's app pushes the movements of a bank
 * account. Sending the same movements again is safe: the answer says how many
 * were new and how many were already there.
 *
 * 201 when something new was stored, 200 when everything was already there.
 */
final class ReceiveMovementsController
{
    public function __invoke(ReceiveMovementsRequest $request, ReceiveMovements $receive): JsonResponse
    {
        $result = $receive->handle($request->bank(), $request->company(), $request->movements(), $request->batchLabel());
        $load = $result->load;

        return response()->json([
            'carga' => $load->id,
            'nuevos' => $load->inserted,
            'repetidos' => $load->duplicates,
            'debitos_omitidos' => $load->debits_skipped,
            'errores' => array_map(fn (array $error): array => ['mensaje' => $error['message']], $load->errors ?? []),
        ], $result->inserted() > 0 ? 201 : 200);
    }
}
