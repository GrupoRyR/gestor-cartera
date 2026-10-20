<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Http\Requests\ParameterVersionRequest;
use App\Modules\Comisiones\Http\Requests\StoreCodeVersionRequest;
use App\Modules\Comisiones\Http\Requests\StoreDerivedVersionRequest;
use App\Modules\Comisiones\Http\Requests\StoreOptionsVersionRequest;
use App\Modules\Comisiones\Http\Requests\StoreTeamVersionRequest;
use App\Modules\Comisiones\Http\Requests\StoreTrancheVersionRequest;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterCatalog;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterChangeRefused;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterVersions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The forms that add a parameter version (§7.2, comisiones.parameters):
 *
 * - GET /comisiones/parametros/{tipo}/nueva?clave=… (comisiones.parametros.create):
 *   the form of a kind, prefilled from the version of the key in force today
 *   (else its latest one); without ?clave= a code, team or derived commission
 *   starts empty, as a new key;
 * - POST /comisiones/parametros/{tipo} (comisiones.parametros.<tipo>.store):
 *   ParameterVersions::add() with the date and motive. A refusal (closed
 *   period, later version, values the engine rejects) comes back to the form
 *   as the error «version» with what was typed; a version saved goes to the
 *   history of its key.
 */
final class ParameterFormController
{
    public function __construct(
        private readonly ParameterCatalog $catalog,
        private readonly ParameterVersions $versions,
    ) {}

    public function create(Request $request, string $tipo): View
    {
        $kind = ParameterKind::from($tipo);
        $key = $kind->key(is_string($request->query('clave')) ? $request->query('clave') : null);
        $isNew = $kind->keyed() && $key === '';
        $basis = $isNew ? null : $this->catalog->basis($kind->value, $key, ParametersController::today());

        abort_if($kind->keyed() && ! $isNew && $basis === null, 404);

        $firstOpenDay = $this->catalog->firstOpenDay();
        $monthStart = CarbonImmutable::now((string) config('app.timezone'))->startOfMonth()->format('Y-m-d');
        $openDay = $firstOpenDay?->format('Y-m-d');

        return view('comisiones::parametros.nueva', [
            'kind' => $kind,
            'key' => $key,
            'isNew' => $isNew,
            'basis' => $basis,
            'values' => match (true) {
                $basis !== null => $basis->datos,
                $kind === ParameterKind::Options => $this->catalog->defaultOptions(),
                default => [],
            },
            // The month in course, or the first open day when it comes later.
            'from' => $openDay !== null && $openDay > $monthStart ? $openDay : $monthStart,
            'firstOpenDay' => $openDay,
            'closed' => $this->catalog->lastClosedPeriod(),
        ]);
    }

    public function code(StoreCodeVersionRequest $request): RedirectResponse
    {
        return $this->store($request);
    }

    public function tranches(StoreTrancheVersionRequest $request): RedirectResponse
    {
        return $this->store($request);
    }

    public function team(StoreTeamVersionRequest $request): RedirectResponse
    {
        return $this->store($request);
    }

    public function derived(StoreDerivedVersionRequest $request): RedirectResponse
    {
        return $this->store($request);
    }

    public function options(StoreOptionsVersionRequest $request): RedirectResponse
    {
        return $this->store($request);
    }

    private function store(ParameterVersionRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $kind = $request->kind();

        try {
            $record = $this->versions->add($kind->value, $request->key(), $request->from(), $request->versionData(), $request->reason(), $user);
        } catch (ParameterChangeRefused $refused) {
            if ($refused->detail() !== null) {
                Log::info('Comisiones parameter version refused', [
                    'tipo' => $kind->value,
                    'clave' => $request->key(),
                    'vigente_desde' => $request->from(),
                    'detail' => $refused->detail(),
                ]);
            }

            return back()->withErrors(['version' => $refused->getMessage()])->withInput();
        }

        return redirect()
            ->route('comisiones.parametros.historial', ['tipo' => $kind->value, 'clave' => $record->clave])
            ->with('status', __('comisiones::parametros.formulario.guardada', [
                'de' => $kind->of($record->clave, $record),
                'fecha' => $record->vigente_desde->format('d/m/Y'),
            ]));
    }
}
