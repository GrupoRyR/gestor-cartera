<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Domain\Mail\BounceDetector;
use App\Modules\Cobro\Domain\Mail\InboundEnvelope;
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
 * - Each message comes with its message class (PidTagMessageClass, through
 *   $expand); only for a non-delivery report class one more GET reads its
 *   headers, for X-MS-Exchange-Organization-AuthAs. BounceDetector decides
 *   with both whether it is a bounce (audit S-24).
 * - When Graph refuses the first page of a round (HTTP 400 or 501), most
 *   likely for that $expand, it is asked once more without it: the round
 *   goes on without message classes, so every message is kept as a reply.
 * - A message linked to a client is logged in the client's activity as
 *   EmailReceived; a linked bounce also raises BounceLinked.
 * - Graph answering 410 (the sync state expired), or a saved link that
 *   GraphClient refuses (not under Graph v1.0), restarts the round.
 */
final class MailboxSync
{
    /**
     * Characters of the plain-text body kept per message.
     */
    public const int BODY_LIMIT = 20000;

    /**
     * $expand that adds the message class (MAPI property 0x001A) to each
     * message as singleValueExtendedProperties.
     */
    public const string CLASS_EXPAND = "singleValueExtendedProperties(\$filter=id eq 'String 0x001A')";

    /**
     * Name of the header where Exchange says how it authenticated a message.
     */
    public const string AUTH_AS_HEADER = 'X-MS-Exchange-Organization-AuthAs';

    /**
     * Statuses with which Graph refuses the query itself, not the request.
     */
    private const array REFUSED_QUERY = [400, 501];

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
        $withoutClass = false;

        try {
            $url = $cursor->next_link ?? $cursor->delta_link;
            $query = $url === null ? self::initialQuery() : [];
            $url ??= self::deltaPath($mailbox);

            for ($page = 0; $page < max(1, (int) config('cobro.sync.max_pages', 10)); $page++) {
                if ($query === []) {
                    $json = $this->graph->get($url, [], self::preferHeader(50));
                } else {
                    [$json, $withoutClass] = $this->firstPage($mailbox, $url, $query);
                    $query = [];
                }

                foreach ((array) ($json['value'] ?? []) as $message) {
                    if (is_array($message) && ! array_key_exists('@removed', $message) && $this->store($company, $mailbox, $message, $withoutClass)) {
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
            if ($e->status === 410 || $e->graphCode === GraphClient::REJECTED_URL) {
                // The saved state expired or points outside Graph: the next
                // run starts a new round.
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
     * The first page of a new round. When Graph refuses the query itself
     * (REFUSED_QUERY), most likely for CLASS_EXPAND, it asks once more
     * without the $expand and warns once with the mailbox and the status;
     * any other error, or a second refusal, is thrown as usual.
     *
     * @param  array<string, string>  $query
     * @return array{0: array<string, mixed>, 1: bool} The page, and whether it came without the message class.
     *
     * @throws GraphRequestFailed
     */
    private function firstPage(string $mailbox, string $url, array $query): array
    {
        try {
            return [$this->graph->get($url, $query, self::preferHeader(50)), false];
        } catch (GraphRequestFailed $e) {
            if (! in_array($e->status, self::REFUSED_QUERY, true)) {
                throw $e;
            }

            unset($query['$expand']);
            $json = $this->graph->get($url, $query, self::preferHeader(50));

            Log::warning('Cobro: Graph refused the message class in the inbox delta query; until it accepts it, bounces are kept as replies.', [
                'mailbox' => $mailbox,
                'status' => $e->status,
            ]);

            return [$json, true];
        }
    }

    /**
     * Graph path of the inbox delta query of a mailbox.
     */
    public static function deltaPath(string $mailbox): string
    {
        return GraphClient::mailbox($mailbox).'/mailFolders/inbox/messages/delta';
    }

    /**
     * Query of the first page of a round. Graph keeps it inside the next
     * and delta links, so a change here only applies after the round
     * restarts (the cursors are reset by a migration).
     *
     * @return array<string, string>
     */
    public static function initialQuery(): array
    {
        $since = CarbonImmutable::now('UTC')->subDays(max(1, (int) config('cobro.sync.initial_days', 30)))->startOfDay();

        return [
            '$select' => self::SELECT,
            '$expand' => self::CLASS_EXPAND,
            '$filter' => 'receivedDateTime ge '.$since->format('Y-m-d\TH:i:s\Z'),
            'changeType' => 'created',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function preferHeader(int $pageSize): array
    {
        return ['Prefer' => "odata.maxpagesize={$pageSize}, outlook.body-content-type=\"text\""];
    }

    /**
     * The message class of a Graph message read with CLASS_EXPAND, or null
     * when Graph did not return it.
     *
     * @param  array<array-key, mixed>  $message
     */
    public static function messageClass(array $message): ?string
    {
        foreach ((array) ($message['singleValueExtendedProperties'] ?? []) as $property) {
            $id = is_array($property) ? ($property['id'] ?? null) : null;
            $value = is_array($property) ? ($property['value'] ?? null) : null;

            if (is_string($id) && preg_match('/^String\s+0x0*1A$/i', trim($id)) === 1) {
                return is_string($value) && trim($value) !== '' ? trim($value) : null;
            }
        }

        return null;
    }

    /**
     * The value of a header of a Graph message read with
     * $select=internetMessageHeaders (the first one with that name, in any
     * case), or null.
     *
     * @param  array<array-key, mixed>  $message
     */
    public static function header(array $message, string $name): ?string
    {
        foreach ((array) ($message['internetMessageHeaders'] ?? []) as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && strcasecmp(trim($header['name']), $name) === 0) {
                return is_string($header['value'] ?? null) ? trim($header['value']) : null;
            }
        }

        return null;
    }

    /**
     * What the bounce rule reads from a message, with the domains of every
     * company mailbox and of the tenant (cobro.tenant_domains) as ours.
     */
    public function envelope(string $from, ?string $messageClass, ?string $authAs): InboundEnvelope
    {
        $domains = array_map(
            fn (string $address): string => (string) substr($address, (int) strrpos($address, '@') + 1),
            array_values(array_filter($this->mailboxes->all(), fn (string $address): bool => str_contains($address, '@'))),
        );
        $tenant = array_values(array_filter((array) config('cobro.tenant_domains', []), is_string(...)));

        return new InboundEnvelope($from, $messageClass, $authAs, [...$domains, ...$tenant]);
    }

    /**
     * Stores one Graph message unless it is already stored or was sent by
     * the mailbox itself.
     *
     * @param  array<array-key, mixed>  $message
     * @param  bool  $withoutClass  Whether the page was read without CLASS_EXPAND.
     */
    private function store(CompanyCode $company, string $mailbox, array $message, bool $withoutClass): bool
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
        $kind = $this->classify($mailbox, $graphId, $from, $message, $withoutClass);

        // Another server's report counts only when it quotes the Message-ID
        // of a send: without a conversation the linker runs that step alone.
        // A report that quotes none is a reply, linked as one.
        $sent = $kind === BounceDetector::EXTERNAL_REPORT ? $this->linker->find($mailbox, null, true, $body, $from) : null;

        if ($sent === null) {
            $kind = $kind === BounceDetector::EXTERNAL_REPORT ? BounceDetector::REPLY : $kind;
            $sent = $this->linker->find($mailbox, $conversationId, $kind === BounceDetector::OWN_REPORT, $body, $from);
        }

        $isBounce = $kind !== BounceDetector::REPLY;
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

    /**
     * BounceDetector's kind of a message. Without a message class it is a
     * reply; the warning names only the mailbox and the Graph id, and is
     * left out when the page was read without the class (firstPage()
     * already warned for the whole run).
     *
     * @param  array<array-key, mixed>  $message
     */
    private function classify(string $mailbox, string $graphId, string $from, array $message, bool $withoutClass): string
    {
        $class = self::messageClass($message);

        if ($class === null) {
            if (! $withoutClass) {
                Log::warning('Cobro: an inbound message came without its message class; it is kept as a reply.', ['mailbox' => $mailbox, 'graph_id' => $graphId]);
            }

            return BounceDetector::REPLY;
        }

        $authAs = BounceDetector::isFailureClass($class) ? $this->authAs($mailbox, $graphId) : null;

        return $this->bounces->classify($this->envelope($from, $class, $authAs));
    }

    /**
     * X-MS-Exchange-Organization-AuthAs of a message, read with one more
     * GET. When Graph fails it is unknown, so the message cannot be our
     * Exchange's report.
     */
    private function authAs(string $mailbox, string $graphId): ?string
    {
        try {
            $headers = $this->graph->get(GraphClient::mailbox($mailbox).'/messages/'.rawurlencode($graphId), ['$select' => 'internetMessageHeaders']);
        } catch (GraphRequestFailed $e) {
            Log::warning('Cobro: the headers of a delivery report could not be read; its sender counts as unauthenticated.', [
                'mailbox' => $mailbox,
                'graph_id' => $graphId,
                'status' => $e->status,
            ]);

            return null;
        }

        return self::header($headers, self::AUTH_AS_HEADER);
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
