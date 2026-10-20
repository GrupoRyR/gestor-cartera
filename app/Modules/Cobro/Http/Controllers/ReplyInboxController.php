<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The client replies inbox (Respuestas de clientes), cobro.respuestas.*:
 * the replies and bounces read from the company inboxes of
 * CurrentCompany::scope().
 *
 * - GET  /cobro/respuestas?estado=pendientes|leidas|todas: the list,
 *   pending first by default, newest first;
 * - GET  /cobro/respuestas/{message}: one message with its text;
 * - POST /cobro/respuestas/marcar: marks the checked messages (mensajes[])
 *   as read or pending (accion=leida|pendiente).
 *
 * A message of another company answers 404 and is never marked.
 */
final class ReplyInboxController
{
    public const int PER_PAGE = 25;

    /**
     * @var list<string>
     */
    public const array FILTERS = ['pendientes', 'leidas', 'todas'];

    public function __construct(
        private readonly CurrentCompany $currentCompany,
    ) {}

    public function index(Request $request): View
    {
        $filter = in_array($request->query('estado'), self::FILTERS, true) ? (string) $request->query('estado') : 'pendientes';
        $companies = $this->companies();

        $messages = InboundMessage::query()
            ->ofCompanies($companies)
            ->when($filter === 'pendientes', fn (Builder $query) => $query->whereNull('read_at'))
            ->when($filter === 'leidas', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('cobro::respuestas.index', [
            'messages' => $messages,
            'filter' => $filter,
            'pending' => InboundMessage::query()->ofCompanies($companies)->whereNull('read_at')->count(),
            'isGroup' => $this->currentCompany->isGroup(),
        ]);
    }

    public function show(InboundMessage $message): View
    {
        $this->authorizeCompany($message);
        $message->loadMissing(['sentMessage', 'reader']);

        return view('cobro::respuestas.show', ['message' => $message]);
    }

    public function mark(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mensajes' => ['required', 'array', 'min:1', 'max:200'],
            'mensajes.*' => ['integer', 'min:1'],
            'accion' => ['required', 'in:leida,pendiente'],
        ], ['mensajes.required' => __('cobro::respuestas.mensajes.marca_uno'), 'mensajes.min' => __('cobro::respuestas.mensajes.marca_uno')]);

        $read = $validated['accion'] === 'leida';

        $count = InboundMessage::query()
            ->ofCompanies($this->companies())
            ->whereKey(array_map('intval', (array) $validated['mensajes']))
            ->update($read
                ? ['read_at' => CarbonImmutable::now(), 'read_by' => ((int) $request->user()?->getAuthIdentifier()) ?: null]
                : ['read_at' => null, 'read_by' => null]);

        return back()->with('status', trans_choice(
            $read ? 'cobro::respuestas.mensajes.leidas' : 'cobro::respuestas.mensajes.pendientes',
            $count,
            ['total' => $count],
        ));
    }

    /**
     * @return list<CompanyCode>
     */
    private function companies(): array
    {
        return array_map(fn (Company $company): CompanyCode => $company->code, $this->currentCompany->scope());
    }

    private function authorizeCompany(InboundMessage $message): void
    {
        abort_unless(in_array($message->company, $this->companies(), true), 404);
    }
}
