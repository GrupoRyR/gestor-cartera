<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;
use App\Modules\Core\Http\Requests\SwitchCompanyRequest;
use Illuminate\Http\RedirectResponse;

/**
 * POST /empresa (core.empresa.cambiar): the company selector of the layout.
 */
final class SwitchCompanyController
{
    public function __invoke(SwitchCompanyRequest $request, CurrentCompany $currentCompany): RedirectResponse
    {
        try {
            $currentCompany->switchTo($request->company());
        } catch (CompanyNotAvailable $exception) {
            return back()->with('error', __($exception->company === null
                ? 'core::empresas.grupo_no_disponible'
                : 'core::empresas.no_disponible'));
        }

        return back()->with('status', __('core::empresas.cambiada', [
            'empresa' => $currentCompany->active()->name ?? __('core::empresas.grupo'),
        ]));
    }
}
