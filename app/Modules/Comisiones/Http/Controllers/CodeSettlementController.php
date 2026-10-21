<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Modules\Comisiones\Domain\Calculation\CollectionDetail;
use App\Modules\Comisiones\Domain\Calculation\SaleDetail;
use App\Modules\Comisiones\Http\Requests\CodeLinesRequest;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Http\Support\SelectedSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\CorruptSettlement;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * GET /comisiones/periodos/{periodo}/codigos/{codigo}?version=N
 * (comisiones.periodos.codigo), the settlement of one code (§7.5): the
 * rule applied, sales, budget, compliance and commissions, its sales and
 * collection lines and its captures. The filters narrow the lines; the
 * figures of the code stay those of the settlement. Every code of the
 * consolidated report links here (§2.3, drill-down).
 *
 * A period never calculated, or a code the settlement does not hold,
 * answers 404.
 */
final class CodeSettlementController
{
    public function __construct(
        private readonly EloquentSettlements $settlements,
    ) {}

    public function show(CodeLinesRequest $request, string $periodo, int $codigo): View|RedirectResponse
    {
        $period = PeriodRoute::period($periodo);

        try {
            $selected = SelectedSettlement::from($request, $period, $this->settlements);
        } catch (CorruptSettlement $corrupt) {
            report($corrupt);

            return redirect()->route('comisiones.periodos.show', $period->key());
        }

        $code = $selected->settlement?->result->code($codigo);
        abort_if($code === null, 404);

        $filters = $request->filters();

        return view('comisiones::periodos.codigo', [
            'period' => $period,
            'selected' => $selected,
            'code' => $code,
            'filters' => $filters,
            'tranches' => $selected->settlement->result->parameters()->tranches(),
            'sales' => array_values(array_filter($code->saleDetails, fn (SaleDetail $line): bool => $filters->sale($line))),
            'collections' => array_values(array_filter($code->collectionDetails, fn (CollectionDetail $line): bool => $filters->collection($line))),
        ]);
    }
}
