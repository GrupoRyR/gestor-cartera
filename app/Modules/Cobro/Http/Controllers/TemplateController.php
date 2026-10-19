<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Http\Requests\UpdateTemplateRequest;
use App\Modules\Cobro\Infrastructure\Mail\NoticeRenderer;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Cobro\Infrastructure\Templates\TemplateStore;
use App\Modules\Cobro\Providers\CobroServiceProvider;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The weekly collection template of each company (cobro.plantillas.*):
 *
 * - GET /cobro/plantillas?empresa=SGX: the editor, one tab per company
 *   the user may choose (CurrentCompany::available());
 * - PUT /cobro/plantillas/{empresa}: saves the texts;
 * - POST /cobro/plantillas/{empresa}/restaurar: back to the defaults;
 * - GET /cobro/plantillas/{empresa}/vista-previa: the mail with sample
 *   data, as the client would see it.
 *
 * A company the user may not choose answers 404.
 */
final class TemplateController
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly TemplateStore $templates,
    ) {}

    public function edit(Request $request): View
    {
        $companies = $this->currentCompany->available();
        abort_if($companies === [], 404);

        $selected = $this->company($request->string('empresa')->toString())
            ?? $this->currentCompany->active()
            ?? $companies[0];

        return view('cobro::plantillas.edit', [
            'companies' => $companies,
            'company' => $selected,
            'template' => $this->templates->for($selected->code, RunKind::Weekly),
            'saved' => Template::query()->with('editor')->where('company', $selected->code)->where('kind', RunKind::Weekly)->first(),
        ]);
    }

    public function update(UpdateTemplateRequest $request, string $empresa): RedirectResponse
    {
        $company = $this->company($empresa) ?? abort(404);

        $this->templates->save($company->code, RunKind::Weekly, $request->content(), ((int) $request->user()?->getAuthIdentifier()) ?: null);

        return redirect()
            ->route('cobro.plantillas.edit', ['empresa' => $company->code->value])
            ->with('status', __('cobro::plantillas.mensajes.guardada', ['empresa' => $company->name]));
    }

    public function reset(string $empresa): RedirectResponse
    {
        $company = $this->company($empresa) ?? abort(404);

        $this->templates->reset($company->code, RunKind::Weekly);

        return redirect()
            ->route('cobro.plantillas.edit', ['empresa' => $company->code->value])
            ->with('status', __('cobro::plantillas.mensajes.restaurada', ['empresa' => $company->name]));
    }

    public function preview(NoticeRenderer $renderer, string $empresa): Response
    {
        $company = $this->company($empresa) ?? abort(404);

        $mail = $renderer->render(
            'cobro::correos.cobro-semanal',
            $company,
            self::sample($company->code),
            $this->templates->for($company->code, RunKind::Weekly),
        );

        return response($mail->html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Sample client with two overdue invoices, for the preview.
     */
    public static function sample(CompanyCode $company): NoticeData
    {
        $today = CarbonImmutable::now(CobroServiceProvider::TIMEZONE)->startOfDay();
        $invoice = fn (int $id, string $number, int $days, float $balance): Invoice => new Invoice(
            id: $id,
            company: $company,
            clientId: 0,
            documentNumber: $number,
            documentType: 'Factura',
            issuedAt: $today->subDays($days + 30),
            dueAt: $today->subDays($days),
            amount: $balance,
            balance: $balance,
            daysOverdue: $days,
        );

        return new NoticeData(
            clientName: (string) __('cobro::semanal.ejemplo.cliente'),
            taxId: '900123456',
            invoices: [$invoice(1, 'FV-1188', 47, 2_350_000.0), $invoice(2, 'FV-1201', 18, 1_150_000.0)],
            cutoffDate: $today,
        );
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
