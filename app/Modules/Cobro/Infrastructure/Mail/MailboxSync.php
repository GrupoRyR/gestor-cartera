<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Domain\Mail\BounceDetector;
use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\MailboxCursor;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Reads the new messages of a company inbox with a Graph delta query
 * (/mailFolders/inbox/messages/delta, only created messages) and stores
 * each one once: replies and bounces, linked to the send they answer and
 * its client when InboundLinker finds it.
 *
 * - The first round reads the messages received in the last
 *   cobro.sync.initial_days; later rounds start from the saved deltaLink.
 * - A run reads at most cobro.sync.max_pages pages and saves the nextLink,
 *   so a long round continues in the next run.
 * - A message linked to a client is logged in the client's activity as
 *   EmailReceived; a linked bounce also raises BounceLinked.
 * - Graph answering 410 (the sync state expired) restarts the round.
 */
final class MailboxSync
{
    /**
     * Characters of the plain-text body kept per message.
     */
    public const int BODY_LIMIT = 20000;

    private const string SELECT = 'id,internetMessageId,conversationId,subject,from,receivedDateTime,bodyPreview,body';

    public function __construct(
        private readonly GraphClient $graph,
        private readonly CompanyMailboxes $mailboxes,
        private readonly InboundLinker $linker,
        private readonly BounceDetector $bounces,
        private readonly CarteraRepository $cartera,
        private readonly GestionLogger $activities,
    ) {}

    /**
     * Synchronizes the inbox of $company; returns how many new messages
     * were stored.
     *
     * @throws GraphRequestFailed When Graph fails; the cursor keeps the error.
     */
    public function sync(CompanyCode $company): int
    {
        $mailbox = $this->mailboxes->for($company);
        $cursor = MailboxCursor::query()->firstOrCreate(['mailbox' => $mailbox]);
        $stored = 0;

        try {
            $url = $cursor->next_link ?? $cursor->delta_link;
            $query = $url === null ? $this->initialQuery() : [];
            $url ??= GraphClient::mailbox($mailbox).'/mailFolders/inbox/messages/delta';

            for ($page = 0; $page < max(1, (int) config('cobro.sync.max_pages', 10)); $page++) {
                $json = $this->graph->get($url, $query, [
                    'Prefer' => 'odata.maxpagesize=50, outlook.body-content-type="text"',
                ]);
                $query = [];

                foreach ((array) ($json['value'] ?? []) as $message) {
                    if (is_array($message) && ! array_key_exists('@removed', $message) && $this->store($company, $mailbox, $message)) {
                        $stored++;
                    }
                }

                $next = $json['@odata.nextLink'] ?? null;
                $delta = $json['@odata.deltaLink'] ?? null;

                if (is_string($next) && $next !== '') {
                    $cursor->next_link = $url = $next;
                    $cursor->save();

                    continue;
                }

                if (is_string($delta) && $delta !== '') {
                    $cursor->delta_link = $delta;
                }

                $cursor->next_link = null;

                break;
            }
        } catch (GraphRequestFailed $e) {
            if ($e->status === 410) {
                // The saved state expired: the next run starts a new round.
                $cursor->delta_link = null;
                $cursor->next_link = null;
            }

            $cursor->last_error = mb_substr($e->getMessage(), 0, 500);
            $cursor->save();

            throw $e;
        }

        $cursor->last_synced_at = CarbonImmutable::now();
        $cursor->last_error = null;
        $cursor->save();

        return $stored;
    }

    /**
     * @return array<string, string>
     */
    private function initialQuery(): array
    {
        $since = CarbonImmutable::now('UTC')->subDays(max(1, (int) config('cobro.sync.initial_days', 30)))->startOfDay();

        return [
            '$select' => self::SELECT,
            '$filter' => 'receivedDateTime ge '.$since->format('Y-m-d\TH:i:s\Z'),
            'changeType' => 'created',
        ];
    }

    /**
     * Stores one Graph message unless it is already stored or was sent by
     * the mailbox itself.
     *
     * @param  array<array-key, mixed>  $message
     */
    private function store(CompanyCode $company, string $mailbox, array $message): bool
    {
        $graphId = self::text($message, 'id');

        if ($graphId === null || InboundMessage::query()->where('mailbox', $mailbox)->where('graph_id', $graphId)->exists()) {
            return false;
        }

        $from = mb_strtolower(trim((string) data_get($message, 'from.emailAddress.address', '')));

        if ($from === $mailbox) {
            return false;
        }

        $subject = self::text($message, 'subject') ?? '';
        $preview = self::text($message, 'bodyPreview') ?? '';
        $body = mb_substr((string) data_get($message, 'body.content', ''), 0, self::BODY_LIMIT);
        $conversationId = self::text($message, 'conversationId');
        $isBounce = $this->bounces->isBounce($from, $subject);

        $sent = $this->linker->find($mailbox, $conversationId, $isBounce, $body, $from);
        $bounce = $isBounce ? $this->bounces->read($body, $preview, $sent === null ? [] : $sent->recipients, [$mailbox]) : null;
        $clientId = $sent?->client_id;

        $inbound = InboundMessage::query()->create([
            'company' => $company,
            'mailbox' => $mailbox,
            'graph_id' => $graphId,
            'internet_message_id' => self::text($message, 'internetMessageId'),
            'conversation_id' => $conversationId,
            'from_address' => $from === '' ? null : mb_substr($from, 0, 255),
            'from_name' => self::clip((string) data_get($message, 'from.emailAddress.name', ''), 255),
            'subject' => self::clip($subject, 500) ?? '',
            'preview' => $preview,
            'body' => $body === '' ? null : $body,
            'received_at' => self::receivedAt($message),
            'is_bounce' => $isBounce,
            'bounce_reason' => $bounce?->reason,
            'bounced_recipient' => $bounce?->recipient,
            'sent_message_id' => $sent?->id,
            'client_id' => $clientId,
            'client_name' => $clientId === null ? null : $this->cartera->asSystem()->client($clientId)?->summary->name,
        ]);

        if ($clientId !== null) {
            $this->logActivity($inbound, $clientId);
        }

        if ($isBounce && $sent !== null) {
            event(new BounceLinked($inbound));
        }

        return true;
    }

    private function logActivity(InboundMessage $inbound, int $clientId): void
    {
        $summary = $inbound->is_bounce
            ? __('cobro::respuestas.bitacora.rebote', ['correo' => $inbound->bounced_recipient ?? '—'])
            : __('cobro::respuestas.bitacora.respuesta', ['remitente' => $inbound->from_address ?? '—']);

        try {
            $this->activities->log(new NewActivity(
                clientId: $clientId,
                type: ActivityType::EmailReceived,
                summary: $summary,
                details: $inbound->is_bounce ? $inbound->bounce_reason : $inbound->subject,
                occurredAt: $inbound->received_at,
                metadata: ['cobro_inbound_id' => $inbound->id, 'bounce' => $inbound->is_bounce],
            ));
        } catch (RecordNotFound) {
            Log::info('Cobro: a reply was linked to a client that no longer exists.', ['client_id' => $clientId]);
        }
    }

    /**
     * @param  array<array-key, mixed>  $message
     */
    private static function receivedAt(array $message): CarbonImmutable
    {
        $value = self::text($message, 'receivedDateTime');

        return ($value === null ? CarbonImmutable::now() : CarbonImmutable::parse($value))
            ->setTimezone((string) config('app.timezone'));
    }

    /**
     * @param  array<array-key, mixed>  $message
     */
    private static function text(array $message, string $key): ?string
    {
        $value = $message[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function clip(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
