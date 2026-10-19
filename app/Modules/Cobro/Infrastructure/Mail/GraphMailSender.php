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
 * When the send step fails, the draft is deleted (best effort) and
 * MailNotSent is thrown: nothing reached the recipients.
 */
final class GraphMailSender implements MailSender
{
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

            $this->graph->postEmpty("{$messages}/".rawurlencode($draftId).'/send');
        } catch (GraphRequestFailed $e) {
            if ($draftId !== null) {
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
