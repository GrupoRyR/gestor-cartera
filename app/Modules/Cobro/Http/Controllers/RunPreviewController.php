<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\PlannedItem;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Runs\RunDefinitions;
use App\Modules\Cobro\Infrastructure\Runs\RunPlanner;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

/**
 * The preview of the next run of a collection mail, the same for every
 * RunKind (routes cobro.semanal.* and cobro.circularizacion.*, whose "tipo"
 * route default is the RunKind value, read by name from the route):
 *
 * - GET  …/?empresa=SGX&estado=pendiente: who would get the mail now, with
 *   its status (pending, without e-mail, excluded), the next scheduled
 *   moment and the latest runs;
 * - GET  …/{empresa}/correo/{cliente}: one client's mail as it would leave;
 * - POST …/{empresa}/prueba: mails one client's message, marked as a test,
 *   to the signed-in user only.
 *
 * Only companies of the definition that the user may choose are served.
 * The list is the run itself (every client of the company): cobro.view
 * belongs to roles that see every client. One client's mail and the test
 * read the client through CarteraRepository, so the user's scope applies.
 */
final class RunPreviewController
{
    public const int PER_PAGE = 25;

    public function __construct(
        private readonly RunDefinitions $definitions,
        private readonly RunPlanner $planner,
        private readonly CurrentCompany $currentCompany,
        private readonly CarteraRepository $cartera,
        private readonly MailSender $sender,
    ) {}

    public function show(Request $request): View
    {
        $definition = $this->definition($request);
        $companies = $this->companies($definition);

        if ($companies === []) {
            return view('cobro::envios.show', ['definition' => $definition, 'companies' => [], 'company' => null]);
        }

        $company = $this->pick($companies, $request->string('empresa')->toString()) ?? $companies[0];
        $planned = $this->planner->plan($definition, $company->code);

        $filter = ItemStatus::tryFrom($request->string('estado')->toString());
        $filtered = $filter === null ? $planned : array_values(array_filter($planned, fn (PlannedItem $item): bool => $item->status === $filter));
        $page = LengthAwarePaginator::resolveCurrentPage();

        return view('cobro::envios.show', [
            'definition' => $definition,
            'companies' => $companies,
            'company' => $company,
            'planned' => $planned,
            'filter' => $filter,
            'items' => new LengthAwarePaginator(
                array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
                count($filtered),
                self::PER_PAGE,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            ),
            'counts' => self::counts($planned),
            'amount' => array_sum(array_map(fn (PlannedItem $item): float => $item->status === ItemStatus::Pending ? $item->candidate->amount : 0.0, $planned)),
            'nextRunAt' => $definition->nextRunAt(CarbonImmutable::now()),
            'runs' => Run::query()->where('company', $company->code)->where('kind', $definition->kind())->orderByDesc('scheduled_for')->limit(5)->get(),
        ]);
    }

    public function mail(Request $request, string $empresa, string $cliente): Response
    {
        $definition = $this->definition($request);
        $company = $this->pick($this->companies($definition), $empresa) ?? abort(404);
        $notice = $this->clientNotice($definition, $company, (int) $cliente) ?? abort(404);

        return response($definition->render($company, $notice)->html)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function test(Request $request, string $empresa): RedirectResponse
    {
        $definition = $this->definition($request);
        $company = $this->pick($this->companies($definition), $empresa) ?? abort(404);
        $clientId = (int) $request->validate(['cliente' => ['required', 'integer', 'min:1']])['cliente'];
        $email = trim((string) $request->user()?->getAttribute('email'));

        if ($email === '') {
            return back()->with('error', __('cobro::envios.prueba.sin_correo_usuario'));
        }

        $notice = $this->clientNotice($definition, $company, $clientId);

        if ($notice === null) {
            return back()->with('error', __('cobro::envios.prueba.sin_datos'));
        }

        $mail = $definition->render($company, $notice, test: true);

        try {
            $this->sender->send(new OutgoingMail(
                company: $company->code,
                to: [$email],
                subject: $mail->subject,
                html: $mail->html,
                category: 'prueba',
            ));
        } catch (MailNotSent $e) {
            Log::warning('Cobro: a test mail was not sent.', ['exception' => $e->getMessage()]);

            return back()->with('error', __('cobro::envios.prueba.fallo'));
        }

        return back()->with('status', __('cobro::envios.prueba.enviada', ['correo' => $email]));
    }

    /**
     * The definition of the route's "tipo" default.
     */
    private function definition(Request $request): RunDefinition
    {
        $tipo = $request->route('tipo');
        $kind = (is_string($tipo) ? RunKind::tryFrom($tipo) : null) ?? abort(404);

        return $this->definitions->for($kind);
    }

    /**
     * Companies of the definition the user may choose, in CompanyCode order.
     *
     * @return list<Company>
     */
    private function companies(RunDefinition $definition): array
    {
        return array_values(array_filter(
            $this->currentCompany->available(),
            fn (Company $company): bool => in_array($company->code, $definition->companies(), true),
        ));
    }

    /**
     * The company of $code among $companies; the active one, else null.
     *
     * @param  list<Company>  $companies
     */
    private function pick(array $companies, string $code): ?Company
    {
        $wanted = CompanyCode::tryFrom(mb_strtoupper(trim($code))) ?? $this->currentCompany->active()?->code;

        foreach ($companies as $company) {
            if ($company->code === $wanted) {
                return $company;
            }
        }

        return null;
    }

    /**
     * The client's mail data when the user may see the client and it
     * belongs to $company.
     */
    private function clientNotice(RunDefinition $definition, Company $company, int $clientId): ?NoticeData
    {
        $client = $this->cartera->client($clientId);

        if ($client === null || $client->summary->company !== $company->code) {
            return null;
        }

        return $definition->notice($clientId);
    }

    /**
     * Planned clients per ItemStatus value.
     *
     * @param  list<PlannedItem>  $planned
     * @return array<string, int>
     */
    private static function counts(array $planned): array
    {
        $counts = array_fill_keys(array_map(fn (ItemStatus $status): string => $status->value, ItemStatus::cases()), 0);

        foreach ($planned as $item) {
            $counts[$item->status->value]++;
        }

        return $counts;
    }
}
