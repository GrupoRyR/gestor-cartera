<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Requests\ImportBudgetRequest;
use App\Modules\Comisiones\Http\Requests\UpdateBudgetRequest;
use App\Modules\Comisiones\Http\Support\PeriodRoute;
use App\Modules\Comisiones\Infrastructure\Captures\BudgetFile;
use App\Modules\Comisiones\Infrastructure\Captures\BudgetSheet;
use App\Modules\Comisiones\Infrastructure\Captures\CodesInForce;
use App\Modules\Comisiones\Infrastructure\Captures\PeriodLock;
use App\Modules\Comisiones\Infrastructure\Files\UnreadableFile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The monthly budget of a period (§7.3) under /comisiones/presupuesto:
 *
 * - index (comisiones.view): one row per code in force, with the codes
 *   that would raise «Sin presupuesto»; ?periodo=YYYY-MM, the month in
 *   course by default;
 * - update and destroy (comisiones.capture): the budget of one code;
 * - import (comisiones.capture): the template, all rows or none;
 * - template (comisiones.capture): the CSV prefilled for the period.
 *
 * A closed period (PeriodLock) refuses every change until a complementary
 * settlement opens it again.
 */
final class BudgetController
{
    public function __construct(
        private readonly BudgetSheet $sheet,
        private readonly PeriodLock $lock,
    ) {}

    public function index(Request $request): View
    {
        $period = PeriodRoute::fromQuery($request->query('periodo'), PeriodRoute::current());
        $rows = $this->sheet->rows($period);
        $editors = [];

        foreach ($rows as $row) {
            $editor = $row->budget === null ? null : ($row->budget->editor ?? $row->budget->creator);

            if ($editor !== null) {
                $editors[$row->rule->code] = $editor->name;
            }
        }

        return view('comisiones::presupuesto.index', [
            'period' => $period,
            'rows' => $rows,
            'editors' => $editors,
            'locked' => $this->lock->isLocked($period),
            'canCapture' => $request->user()?->can('comisiones.capture') ?? false,
        ]);
    }

    public function update(UpdateBudgetRequest $request, CodesInForce $codes, string $periodo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $code = $request->code();

        if ($refused = $this->refused($period)) {
            return $refused;
        }

        if (! in_array($code, array_map(fn ($rule) => $rule->code, $codes->of($period)), true)) {
            return $this->back($period)->with('error', __('comisiones::presupuesto.mensajes.codigo_desconocido', ['codigo' => $code, 'periodo' => $period->key()]));
        }

        $saved = $this->sheet->put($period, $code, $request->amount(), $request->channel(), self::user($request));

        return $this->back($period)->with('status', __(
            $saved === null ? 'comisiones::presupuesto.mensajes.sin_cambios' : 'comisiones::presupuesto.mensajes.guardado',
            ['codigo' => $code, 'periodo' => $period->key()],
        ));
    }

    public function destroy(Request $request, string $periodo, string $codigo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);
        $replace = ['codigo' => (int) $codigo, 'periodo' => $period->key()];

        if ($refused = $this->refused($period)) {
            return $refused;
        }

        return $this->sheet->delete($period, (int) $codigo, self::user($request))
            ? $this->back($period)->with('status', __('comisiones::presupuesto.mensajes.eliminado', $replace))
            : $this->back($period)->with('error', __('comisiones::presupuesto.mensajes.no_existe', $replace));
    }

    public function import(ImportBudgetRequest $request, BudgetFile $file, string $periodo): RedirectResponse
    {
        $period = PeriodRoute::period($periodo);

        if ($refused = $this->refused($period)) {
            return $refused;
        }

        $upload = $request->upload();

        try {
            $read = $file->read((string) $upload->getRealPath(), $upload->getClientOriginalExtension(), $period);
        } catch (UnreadableFile $unreadable) {
            return $this->back($period)->withErrors(['archivo' => $unreadable->getMessage()]);
        }

        if ($read['errors'] !== []) {
            $shown = array_slice($read['errors'], 0, BudgetFile::MAX_ERRORS);
            $more = count($read['errors']) - count($shown);

            if ($more > 0) {
                $shown[] = __('comisiones::presupuesto.carga.mas_errores', ['cantidad' => $more]);
            }

            return $this->back($period)
                ->withErrors(['archivo' => __('comisiones::presupuesto.carga.con_errores')])
                ->with('errores_carga', $shown);
        }

        $counts = $this->sheet->import($period, $read['lines'], $upload->getClientOriginalName(), self::user($request));

        return $this->back($period)->with('status', __('comisiones::presupuesto.carga.listo', ['periodo' => $period->key(), ...$counts]));
    }

    public function template(string $periodo): StreamedResponse
    {
        $period = PeriodRoute::period($periodo);
        $content = $this->sheet->template($period);

        return response()->streamDownload(
            function () use ($content): void {
                echo $content;
            },
            "Plantilla_Presupuesto_{$period->key()}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** The redirect that refuses a change in a closed period, or null when it is open. */
    private function refused(Period $period): ?RedirectResponse
    {
        return $this->lock->isLocked($period) ? $this->back($period)->with('error', PeriodLock::message()) : null;
    }

    private function back(Period $period): RedirectResponse
    {
        return redirect()->route('comisiones.presupuesto.index', ['periodo' => $period->key()]);
    }

    private static function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
