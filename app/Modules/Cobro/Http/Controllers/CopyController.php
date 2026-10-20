<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Http\Requests\UpdateCopiesRequest;
use App\Modules\Cobro\Infrastructure\Copies\CopyStore;
use App\Modules\Cobro\Infrastructure\Persistence\CopySetting;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The carbon copies of each company's weekly collection mail
 * (cobro.copias.*):
 *
 * - GET /cobro/copias?empresa=SGX: the editor, one tab per company the
 *   user may choose (CurrentCompany::available());
 * - PUT /cobro/copias/{empresa}: saves them;
 * - DELETE /cobro/copias/{empresa}: back to the defaults.
 *
 * A company the user may not choose answers 404.
 */
final class CopyController
{
    /**
     * Empty manager rows the editor adds after the saved ones.
     */
    public const int EMPTY_ROWS = 3;

    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly CopyStore $copies,
    ) {}

    public function edit(Request $request): View
    {
        $companies = $this->currentCompany->available();
        abort_if($companies === [], 404);

        $selected = $this->company($request->string('empresa')->toString())
            ?? $this->currentCompany->active()
            ?? $companies[0];

        return view('cobro::copias.edit', [
            'companies' => $companies,
            'company' => $selected,
            'settings' => $this->copies->for($selected->code),
            'saved' => CopySetting::query()->with('editor')->where('company', $selected->code)->first(),
            'emptyRows' => self::EMPTY_ROWS,
        ]);
    }

    public function update(UpdateCopiesRequest $request, string $empresa): RedirectResponse
    {
        $company = $this->company($empresa) ?? abort(404);

        $this->copies->save($company->code, $request->settings(), ((int) $request->user()?->getAuthIdentifier()) ?: null);

        return redirect()
            ->route('cobro.copias.edit', ['empresa' => $company->code->value])
            ->with('status', __('cobro::copias.mensajes.guardadas', ['empresa' => $company->name]));
    }

    public function reset(Request $request, string $empresa): RedirectResponse
    {
        $company = $this->company($empresa) ?? abort(404);

        $this->copies->reset($company->code, ((int) $request->user()?->getAuthIdentifier()) ?: null);

        return redirect()
            ->route('cobro.copias.edit', ['empresa' => $company->code->value])
            ->with('status', __('cobro::copias.mensajes.restauradas', ['empresa' => $company->name]));
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
