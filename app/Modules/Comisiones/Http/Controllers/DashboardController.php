<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Modules\Comisiones\Domain\Parameters\MissingParameters;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Infrastructure\Dashboard\ComplianceBars;
use App\Modules\Comisiones\Infrastructure\Dashboard\LoadBoard;
use App\Modules\Comisiones\Infrastructure\Dashboard\PendingCaptures;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\LivePreview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /comisiones?periodo=YYYY-MM (comisiones.tablero), the dashboard of a
 * period (§7.1), the month in course by default:
 *
 * - the SAP loads of the Comisiones datasets: hour, rows, rejections;
 * - the month to date per code from a live preview of the engine (nothing is
 *   stored): sales, budget, compliance against the minimum, collections and
 *   the commissions they would pay, with a compliance chart;
 * - the alerts of the preview, the captures waiting for an approval and the
 *   status of the stored settlement, linking to it.
 */
final class DashboardController
{
    public function __invoke(Request $request, LivePreview $preview, LoadBoard $loads, PendingCaptures $pending): View
    {
        $period = PeriodRoute::fromQuery($request->query('periodo'), PeriodRoute::current());

        try {
            $result = $preview->calculate($period);
            $missingParameters = false;
        } catch (MissingParameters) {
            $result = null;
            $missingParameters = true;
        }

        $codes = $result === null ? [] : ComplianceBars::listed($result);

        return view('comisiones::tablero', [
            'period' => $period,
            'result' => $result,
            'missingParameters' => $missingParameters,
            'codes' => $codes,
            'chart' => ComplianceBars::of($codes),
            'loads' => $loads->rows(),
            'pending' => $pending->of($period),
            'settlement' => SettlementRecord::query()->where('periodo', $period->key())->orderByDesc('version')->first(),
        ]);
    }
}
