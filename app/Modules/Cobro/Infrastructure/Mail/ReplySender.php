<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Answers a client message from its company mailbox, in the same thread.
 *
 * Graph steps, with the credential of the message's company: createReply
 * on the message (a draft addressed by Graph, with the original quoted),
 * PATCH of the draft body with the user's text and the company's signature
 * (cobro::correos.firma) above the quote, one POST …/attachments per
 * signature image (inline, for its cid: reference) and send. The reply is
 * kept in cobro_sent_messages (category
 * "respuesta"), so what the client answers next is linked to the client
 * like any other send.
 *
 * When a step fails the draft is deleted (best effort), unless the send
 * got no answer at all: then the draft stays for a person to check in
 * Outlook, as it may have left.
 */
final class ReplySender
{
    public const string CATEGORY = 'respuesta';

    public function __construct(
        private readonly GraphClient $graph,
        private readonly NoticeRenderer $renderer,
        private readonly CurrentCompany $companies,
    ) {}

    /**
     * Sends $text as the answer to $message, whose current Graph id is
     * $graphId (MessageLocator).
     *
     * @throws GraphRequestFailed
     */
    public function reply(InboundMessage $message, string $graphId, string $text): SentReply
    {
        $graph = $this->graph->for($message->company);
        $messages = GraphClient::mailbox($message->mailbox).'/messages';
        $draftId = null;
        $keepDraft = false;

        try {
            $draft = $graph->post("{$messages}/".rawurlencode($graphId).'/createReply', ['comment' => '']);
            $draftId = self::string($draft, 'id') ?? throw new GraphRequestFailed('Graph created a reply draft without an id.');
            $internetMessageId = self::string($draft, 'internetMessageId') ?? throw new GraphRequestFailed('Graph created a reply draft without an internetMessageId.');
            $to = self::recipients($draft);

            if ($to === []) {
                throw new GraphRequestFailed('Graph created a reply draft without recipients.');
            }

            $quoted = is_array($draft['body'] ?? null) ? (string) ($draft['body']['content'] ?? '') : '';
            $signature = $this->renderer->signature($this->companies->of($message->company));
            $graph->patch("{$messages}/".rawurlencode($draftId), [
                'body' => ['contentType' => 'HTML', 'content' => self::withText($quoted, $text, $signature['html'])],
            ]);

            // The images the signature shows with cid:, inline on the draft.
            foreach ($signature['attachments'] as $attachment) {
                $graph->post("{$messages}/".rawurlencode($draftId).'/attachments', GraphMailSender::attachment($attachment));
            }

            try {
                $graph->postEmpty("{$messages}/".rawurlencode($draftId).'/send');
            } catch (GraphRequestFailed $e) {
                $keepDraft = $e->status === null;

                throw $e;
            }
        } catch (GraphRequestFailed $e) {
            if ($draftId !== null && ! $keepDraft) {
                $this->discard($graph, $messages, $draftId);
            }

            throw $e;
        }

        $reply = new SentReply(
            mailbox: $message->mailbox,
            to: $to,
            subject: self::string($draft, 'subject') ?? $message->subject,
            internetMessageId: $internetMessageId,
            conversationId: self::string($draft, 'conversationId') ?? $message->conversation_id,
            sentAt: CarbonImmutable::now(),
        );
        $this->record($message, $reply);

        return $reply;
    }

    /**
     * The user's text, escaped and with its line breaks, then $signature
     * (rendered HTML), right after the <body> tag of the quoted draft (or
     * before it, without one).
     */
    public static function withText(string $quoted, string $text, string $signature = ''): string
    {
        $html = '<div style="font-family:Helvetica,Arial,\'Helvetica Neue\',sans-serif;font-size:13px;line-height:1.5;">'.nl2br(e($text), false).'</div>'
            .$signature.'<br>';

        if (preg_match('/<body\b[^>]*>/i', $quoted, $match, PREG_OFFSET_CAPTURE) === 1) {
            $at = $match[0][1] + strlen($match[0][0]);

            return substr($quoted, 0, $at).$html.substr($quoted, $at);
        }

        return $html.$quoted;
    }

    /**
     * Keeps the ids of the reply. It already left, so a database failure
     * is logged instead of reported as not sent.
     */
    private function record(InboundMessage $message, SentReply $reply): void
    {
        try {
            SentMessage::query()->create([
                'company' => $message->company,
                'mailbox' => $reply->mailbox,
                'client_id' => $message->client_id,
                'category' => self::CATEGORY,
                'subject' => mb_substr($reply->subject, 0, 500),
                'recipients' => $reply->to,
                'internet_message_id' => $reply->internetMessageId,
                'conversation_id' => $reply->conversationId,
                'sent_at' => $reply->sentAt,
            ]);
        } catch (Throwable $e) {
            Log::error('Cobro: a sent reply could not be recorded; its answers will not be linked.', [
                'inbound_message' => $message->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function discard(GraphClient $graph, string $messages, string $draftId): void
    {
        try {
            $graph->delete("{$messages}/".rawurlencode($draftId));
        } catch (Throwable $e) {
            Log::warning('Cobro: an unsent reply draft could not be deleted.', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * The To addresses Graph put on the draft, lowercase.
     *
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    private static function recipients(array $draft): array
    {
        $addresses = [];

        foreach ((array) ($draft['toRecipients'] ?? []) as $recipient) {
            $address = is_array($recipient) && is_array($recipient['emailAddress'] ?? null) ? ($recipient['emailAddress']['address'] ?? null) : null;

            if (is_string($address) && trim($address) !== '') {
                $addresses[] = mb_strtolower(trim($address));
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private static function string(array $json, string $key): ?string
    {
        $value = $json[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
