<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Http\Requests\UpdateSignatureRequest;
use App\Modules\Cobro\Infrastructure\Persistence\SignatureSetting;
use App\Modules\Cobro\Infrastructure\Signatures\SignatureStore;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Who signs each company's Cobro mails (cobro.firma.*):
 *
 * - GET /cobro/firma?empresa=SGX: the editor, one tab per company the user
 *   may choose (CurrentCompany::available());
 * - PUT /cobro/firma/{empresa}: saves the person;
 * - DELETE /cobro/firma/{empresa}: back to the default person.
 *
 * A company the user may not choose answers 404.
 */
final class SignatureController
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly SignatureStore $signatures,
    ) {}

    public function edit(Request $request): View
    {
        $companies = $this->currentCompany->available();
        abort_if($companies === [], 404);

        $selected = $this->company($request->string('empresa')->toString())
            ?? $this->currentCompany->active()
            ?? $companies[0];

        return view('cobro::firma.edit', [
            'companies' => $companies,
            'company' => $selected,
            'person' => $this->signatures->for($selected->code),
            'saved' => SignatureSetting::query()->with('editor')->where('company', $selected->code)->first(),
        ]);
    }

    public function update(UpdateSignatureRequest $request, string $empresa): RedirectResponse
    {
        $company = $this->company($empresa) ?? abort(404);

        $this->signatures->save($company->code, $request->person(), ((int) $request->user()?->getAuthIdentifier()) ?: null);

        return redirect()
            ->route('cobro.firma.edit', ['empresa' => $company->code->value])
            ->with('status', __('cobro::firma.mensajes.guardada', ['empresa' => $company->name]));
    }

    public function reset(Request $request, string $empresa): RedirectResponse
    {
        $company = $this->company($empresa) ?? abort(404);

        $this->signatures->reset($company->code, ((int) $request->user()?->getAuthIdentifier()) ?: null);

        return redirect()
            ->route('cobro.firma.edit', ['empresa' => $company->code->value])
            ->with('status', __('cobro::firma.mensajes.restaurada', ['empresa' => $company->name]));
    }

    private function company(string $code): ?Company
    {
        $code = CompanyCode::tryFrom(mb_strtoupper(trim($code)));

        foreach ($this->currentCompany->available() as $company) {
            if ($company->code === $code) {
                return $company;
            }
        }

        return null;
    }
}
