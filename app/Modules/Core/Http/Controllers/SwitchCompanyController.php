<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;
use App\Modules\Core\Http\Requests\SwitchCompanyRequest;
use Illuminate\Http\RedirectResponse;

/**
 * POST /empresa (core.empresa.cambiar): the company selector of the layout.
 *
 * The switch changes which clients the user sees, so it is audited as
 * core.company.switched with the company before and after ("grupo" for the
 * group view, as in the selector). Choosing the active company again audits
 * nothing.
 */
final class SwitchCompanyController
{
    public function __invoke(SwitchCompanyRequest $request, CurrentCompany $currentCompany, AuditLogger $audit): RedirectResponse
    {
        $before = self::selected($currentCompany);

        try {
            $currentCompany->switchTo($request->company());
        } catch (CompanyNotAvailable $exception) {
            return back()->with('error', __($exception->company === null
                ? 'core::empresas.grupo_no_disponible'
                : 'core::empresas.no_disponible'));
        }

        $after = self::selected($currentCompany);

        if ($after !== $before) {
            $audit->record('core.company.switched', changes: ['company' => ['old' => $before, 'new' => $after]]);
        }

        return back()->with('status', __('core::empresas.cambiada', [
            'empresa' => $currentCompany->active()->name ?? __('core::empresas.grupo'),
        ]));
    }

    /**
     * The selector value of the active company: its code, or "grupo".
     */
    private static function selected(CurrentCompany $currentCompany): string
    {
        return $currentCompany->active()->code->value ?? 'grupo';
    }
}
