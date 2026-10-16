<?php

namespace App\Modules\Core\Http\Controllers\Companies;

use App\Modules\Core\Application\Companies\CompanyParameters;
use App\Modules\Core\Application\Companies\UpdateCompanyParameters;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Http\Requests\Companies\UpdateCompanyParametersRequest;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The Parámetros screen (core.empresas.*), for admins only
 * (core.companies.manage): the grace days and the due-soon window of each
 * company. {empresa} is a CompanyCode value (SGX or SDC); any other value is
 * a 404. UpdateCompanyParameters saves, audits and queues the recompute of
 * the client statuses.
 */
final class CompanyParametersController
{
    public function __construct(private readonly CompanyCatalog $catalog) {}

    /**
     * GET /empresas.
     */
    public function index(): View
    {
        return view('core::empresas.index', ['companies' => CompanyParameters::all($this->catalog)]);
    }

    /**
     * GET /empresas/{empresa}/editar.
     */
    public function edit(CompanyCode $empresa): View
    {
        return view('core::empresas.editar', ['company' => CompanyParameters::of($this->catalog, $empresa)]);
    }

    /**
     * PUT /empresas/{empresa}.
     */
    public function update(UpdateCompanyParametersRequest $request, CompanyCode $empresa, UpdateCompanyParameters $parameters): RedirectResponse
    {
        $message = $parameters->update($empresa, $request->parameters())
            ? 'core::empresas.parametros.mensajes.guardados'
            : 'core::empresas.parametros.mensajes.sin_cambios';

        return redirect()
            ->route('core.empresas.index')
            ->with('status', __($message, ['empresa' => $this->catalog->get($empresa)->name]));
    }
}
