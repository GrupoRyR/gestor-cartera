<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\Data\MailAttachment;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Data\SentMail;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

/**
 * MailSender over Microsoft Graph, from the mailbox of the mail's company.
 *
 * Graph's sendMail answers 202 without ids, so the mail goes in two steps:
 * create a draft in the company mailbox (POST /users/{mailbox}/messages,
 * which returns its internetMessageId and conversationId) and send it
 * (POST …/messages/{id}/send). Both ids are kept in cobro_sent_messages
 * for every accepted mail, so the mailbox sync can join replies and
 * bounces to the send and its client.
 *
 * When Graph refuses a step, the draft is deleted (best effort) and
 * MailNotSent is thrown: nothing reached the recipients. When the send
 * step gets no answer, the draft decides: gone (it was sent) counts as
 * sent; still a draft is deleted and MailNotSent; unknown keeps the draft
 * and throws MailNotSent.
 */
final class GraphMailSender implements MailSender
{
    private const string DRAFT = 'draft';

    private const string GONE = 'gone';

    private const string UNKNOWN = 'unknown';

    public function __construct(
        private readonly GraphClient $graph,
        private readonly CompanyMailboxes $mailboxes,
    ) {}

    public function send(OutgoingMail $mail): SentMail
    {
        try {
            $mailbox = $this->mailboxes->for($mail->company);
        } catch (UnexpectedValueException $e) {
            throw new MailNotSent($e->getMessage(), previous: $e);
        }

        $messages = GraphClient::mailbox($mailbox).'/messages';
        $draftId = null;
        $keepDraft = false;

        try {
            $draft = $this->graph->post($messages, $this->message($mail));
            $draftId = self::string($draft, 'id') ?? throw new GraphRequestFailed('Graph created a draft without an id.');

            $internetMessageId = self::string($draft, 'internetMessageId');
            $conversationId = self::string($draft, 'conversationId');

            if ($internetMessageId === null) {
                $read = $this->graph->get("{$messages}/".rawurlencode($draftId), ['$select' => 'internetMessageId,conversationId']);
                $internetMessageId = self::string($read, 'internetMessageId')
                    ?? throw new GraphRequestFailed('Graph did not return the internetMessageId of the draft.');
                $conversationId ??= self::string($read, 'conversationId');
            }

            try {
                $this->graph->postEmpty("{$messages}/".rawurlencode($draftId).'/send');
            } catch (GraphRequestFailed $e) {
                // Graph answered with an error: refused, the draft can go.
                // No answer at all: it may have sent anyway. A sent draft
                // stops resolving (it moved to Sent Items); one that is
                // still a draft did not leave; otherwise nobody knows, and
                // the draft stays for a person to check.
                $state = $e->status === null ? $this->draftState($messages, $draftId) : self::DRAFT;

                if ($state !== self::GONE) {
                    $keepDraft = $state === self::UNKNOWN;

                    throw $e;
                }

                Log::warning('Cobro: Graph did not answer the send, but the draft already left; it counts as sent.', ['mailbox' => $mailbox]);
            }
        } catch (GraphRequestFailed $e) {
            if ($draftId !== null && ! $keepDraft) {
                $this->discardDraft($messages, $draftId);
            }

            throw new MailNotSent("Graph did not send the {$mail->category} mail from {$mailbox}: {$e->getMessage()}", previous: $e);
        }

        $sentAt = CarbonImmutable::now();
        $this->record($mail, $mailbox, $internetMessageId, $conversationId, $sentAt);

        return new SentMail($mailbox, $internetMessageId, $conversationId, $sentAt);
    }

    /**
     * Keeps the ids of an accepted mail. The mail already left, so a
     * database failure here is logged instead of reported as not sent.
     */
    private function record(OutgoingMail $mail, string $mailbox, string $internetMessageId, ?string $conversationId, CarbonImmutable $sentAt): void
    {
        try {
            SentMessage::query()->create([
                'company' => $mail->company,
                'mailbox' => $mailbox,
                'client_id' => $mail->clientId,
                'category' => $mail->category,
                'subject' => mb_substr($mail->subject, 0, 500),
                'recipients' => array_values(array_unique(array_map(
                    fn (string $address): string => mb_strtolower(trim($address)),
                    [...$mail->to, ...$mail->cc],
                ))),
                'internet_message_id' => $internetMessageId,
                'conversation_id' => $conversationId,
                'sent_at' => $sentAt,
            ]);
        } catch (Throwable $e) {
            Log::error('Cobro: a sent mail could not be recorded; its replies will not be linked.', [
                'internet_message_id' => $internetMessageId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The Graph message resource of the draft.
     *
     * @return array<string, mixed>
     */
    private function message(OutgoingMail $mail): array
    {
        $recipients = fn (array $addresses): array => array_map(
            fn (string $address): array => ['emailAddress' => ['address' => trim($address)]],
            array_values($addresses),
        );

        $message = [
            'subject' => $mail->subject,
            'body' => ['contentType' => 'HTML', 'content' => $mail->html],
            'toRecipients' => $recipients($mail->to),
        ];

        if ($mail->cc !== []) {
            $message['ccRecipients'] = $recipients($mail->cc);
        }

        if ($mail->replyTo !== null && trim($mail->replyTo) !== '') {
            $message['replyTo'] = $recipients([$mail->replyTo]);
        }

        if ($mail->attachments !== []) {
            $message['attachments'] = array_map(fn (MailAttachment $attachment): array => [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment->filename,
                'contentType' => $attachment->contentType,
                'contentBytes' => base64_encode($attachment->content),
            ], $mail->attachments);
        }

        return $message;
    }

    /**
     * Where a draft stands after an unanswered send: still a draft, gone
     * from its id (sent), or unknown when Graph does not answer either.
     */
    private function draftState(string $messages, string $draftId): string
    {
        try {
            $draft = $this->graph->get("{$messages}/".rawurlencode($draftId), ['$select' => 'isDraft']);

            return ($draft['isDraft'] ?? true) === false ? self::GONE : self::DRAFT;
        } catch (GraphRequestFailed $e) {
            return $e->status === 404 ? self::GONE : self::UNKNOWN;
        }
    }

    private function discardDraft(string $messages, string $draftId): void
    {
        try {
            $this->graph->delete("{$messages}/".rawurlencode($draftId));
        } catch (Throwable $e) {
            Log::warning('Cobro: an unsent Graph draft could not be deleted.', ['exception' => $e->getMessage()]);
        }
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
