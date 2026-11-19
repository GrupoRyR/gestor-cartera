<?php

namespace App\Modules\Cobro\Http\Controllers;

use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Mail\MessageLocator;
use App\Modules\Cobro\Infrastructure\Mail\ReplySender;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The client replies inbox (Respuestas de clientes), cobro.respuestas.*:
 * the replies and bounces read from the company inboxes of
 * CurrentCompany::scope().
 *
 * - GET  /cobro/respuestas?estado=pendientes|leidas|todas: the list,
 *   pending first by default, newest first;
 * - GET  /cobro/respuestas/{message}: one message with its text;
 * - GET  /cobro/respuestas/{message}/outlook: the message in Outlook on
 *   the web (MessageLocator), or back to its screen with the reason;
 * - POST /cobro/respuestas/{message}/responder: answers a reply (not a
 *   bounce) from the company mailbox (ReplySender);
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

    /**
     * Opens the message in Outlook on the web: redirects to the webLink
     * Graph gives for it now, or back to its screen with the reason.
     */
    public function outlook(InboundMessage $message, MessageLocator $locator): RedirectResponse
    {
        $this->authorizeCompany($message);
        $back = redirect()->route('cobro.respuestas.show', $message);

        try {
            $located = $locator->locate($message);
        } catch (GraphRequestFailed $e) {
            Log::warning('Cobro: the Outlook link of an inbound message could not be read from Graph.', [
                'inbound_message' => $message->id,
                'status' => $e->status,
                'graph_code' => $e->graphCode,
            ]);

            return $back->with('error', __('cobro::respuestas.outlook.fallo'));
        }

        if ($located === null) {
            return $back->with('error', __('cobro::respuestas.outlook.no_esta'));
        }

        if ($located->webLink === null) {
            Log::warning('Cobro: Graph gave no Outlook link for an inbound message.', ['inbound_message' => $message->id]);

            return $back->with('error', __('cobro::respuestas.outlook.fallo'));
        }

        return redirect()->away($located->webLink);
    }

    /**
     * Answers a client reply from the company mailbox (ReplySender), then
     * marks it read, logs it in the client activity and audits it.
     */
    public function reply(Request $request, InboundMessage $message, MessageLocator $locator, ReplySender $sender, GestionLogger $activities, AuditLogger $audit): RedirectResponse
    {
        $this->authorizeCompany($message);
        abort_if($message->is_bounce, 404);

        $text = str_replace("\r\n", "\n", (string) $request->validate(
            ['texto' => ['required', 'string', 'max:10000']],
            ['texto.required' => __('cobro::respuestas.responder.falta_texto')],
        )['texto']);
        $back = redirect()->route('cobro.respuestas.show', $message);

        try {
            $located = $locator->locate($message);

            if ($located === null) {
                return $back->withInput()->with('error', __('cobro::respuestas.outlook.no_esta'));
            }

            $sent = $sender->reply($message, $located->id, $text);
        } catch (GraphRequestFailed $e) {
            Log::warning('Cobro: a reply to an inbound message was not sent.', ['inbound_message' => $message->id, 'status' => $e->status, 'graph_code' => $e->graphCode]);

            return $back->withInput()->with('error', __('cobro::respuestas.responder.fallo'));
        }

        // The reply already left: a failure from here on is reported, never
        // shown as "not sent".
        try {
            $user = $request->user();

            if ($message->read_at === null) {
                $message->update(['read_at' => CarbonImmutable::now(), 'read_by' => $user?->getAuthIdentifier()]);
            }

            $audit->record('cobro.reply.sent', 'cobro_inbound_message', $message->id, context: [
                'client_id' => $message->client_id,
                'to' => $sent->to,
                'internet_message_id' => $sent->internetMessageId,
            ]);

            if ($message->client_id !== null) {
                $activities->log(new NewActivity(
                    clientId: $message->client_id,
                    type: ActivityType::EmailSent,
                    summary: __('cobro::respuestas.responder.bitacora', ['correos' => implode(', ', $sent->to), 'nombre' => $user->name ?? '—']),
                    details: $sent->subject,
                    occurredAt: $sent->sentAt,
                    metadata: ['cobro_inbound_message_id' => $message->id],
                ));
            }
        } catch (RecordNotFound) {
            Log::info('Cobro: an answered client no longer exists; the activity was not logged.', ['client_id' => $message->client_id]);
        } catch (Throwable $e) {
            report($e);
        }

        return $back->with('status', __('cobro::respuestas.responder.enviada', ['correos' => implode(', ', $sent->to)]));
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
