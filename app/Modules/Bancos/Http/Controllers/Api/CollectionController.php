<?php

namespace App\Modules\Bancos\Http\Controllers\Api;

use App\Modules\Bancos\Http\Requests\CollectionRangeRequest;
use App\Modules\Core\Contracts\Data\DailyCollection;
use App\Modules\Core\Contracts\Data\WeeklyCollection;
use App\Modules\Core\Contracts\RecaudoProvider;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/recaudo: the collections (recaudo) per day and per ISO week, for
 * Treasury's cash flow. Read-only. Amounts are Colombian pesos.
 *
 *     {
 *       "desde": "2026-10-01", "hasta": "2026-10-15", "empresa": null, "moneda": "COP",
 *       "diario":  [{"fecha": "2026-10-05", "empresa": "SGX", "total": 1250000.5,
 *                    "por_banco": {"bogota": 1250000.5}, "movimientos": 2}],
 *       "semanal": [{"semana_inicio": "2026-10-05", "semana_fin": "2026-10-11", "empresa": "SGX",
 *                    "total": 1250000.5, "por_banco": {"bogota": 1250000.5}}]
 *     }
 *
 * The days without credits are not listed. The movements of a day arrive the
 * next day, so the current day is normally missing.
 */
final class CollectionController
{
    public function __invoke(CollectionRangeRequest $request, RecaudoProvider $collections): JsonResponse
    {
        $from = $request->from();
        $to = $request->to();
        $company = $request->company();

        return response()->json([
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'empresa' => $company?->value,
            'moneda' => strtoupper((string) config('bancos.collection_currency', 'COP')),
            'diario' => array_map(fn (DailyCollection $day): array => [
                'fecha' => $day->date->toDateString(),
                'empresa' => $day->company->value,
                'total' => $day->total,
                'por_banco' => (object) $day->byBank,
                'movimientos' => $day->movementCount,
            ], $collections->daily($from, $to, $company)),
            'semanal' => array_map(fn (WeeklyCollection $week): array => [
                'semana_inicio' => $week->weekStart->toDateString(),
                'semana_fin' => $week->weekEnd->toDateString(),
                'empresa' => $week->company->value,
                'total' => $week->total,
                'por_banco' => (object) $week->byBank,
            ], $collections->weekly($from, $to, $company)),
        ]);
    }
}
