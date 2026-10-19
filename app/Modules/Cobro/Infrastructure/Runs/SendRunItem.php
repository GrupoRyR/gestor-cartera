<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Contracts\MailSender;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the mail of one run item: renders it with the client's data at
 * send time and hands it to MailSender, then records the outcome on the
 * item and the EmailSent activity of the client.
 *
 * The job first claims the item (pending → enviando in one UPDATE), so a
 * retried or duplicated job never mails a client twice; every failure ends
 * in the item (fallido) instead of a retry. The "cobro-mail" rate limiter
 * keeps the sends under Exchange's per-minute limit: a limited job goes
 * back to the queue, for up to RETRY_HOURS.
 */
final class SendRunItem implements ShouldQueue
{
    use Queueable;

    public const int RETRY_HOURS = 12;

    public function __construct(
        public readonly int $itemId,
    ) {}

    /**
     * Rate-limited releases are retries; this bounds them in time instead
     * of in attempts.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('cobro-mail')];
    }

    public function handle(RunDefinitions $definitions, CurrentCompany $companies, MailSender $sender, GestionLogger $activities, RunFinisher $finisher): void
    {
        $claimed = RunItem::query()
            ->whereKey($this->itemId)
            ->where('status', ItemStatus::Pending)
            ->update(['status' => ItemStatus::Sending]);

        if ($claimed === 0) {
            return;
        }

        $item = RunItem::query()->with('run')->findOrFail($this->itemId);

        try {
            $this->send($item, $definitions, $companies, $sender, $activities);
        } catch (Throwable $e) {
            report($e);
            $item->update(['status' => ItemStatus::Failed, 'error' => __('cobro::envios.errores.inesperado')]);
        } finally {
            $finisher->finishIfDone($item->run_id);
        }
    }

    private function send(RunItem $item, RunDefinitions $definitions, CurrentCompany $companies, MailSender $sender, GestionLogger $activities): void
    {
        $run = $item->run;
        $definition = $definitions->for($run->kind);
        $notice = $definition->notice($item->client_id);

        if ($notice === null) {
            $item->update(['status' => ItemStatus::Skipped]);

            return;
        }

        $company = $companies->of($run->company);
        $mail = $definition->render($company, $notice);

        try {
            $sent = $sender->send(new OutgoingMail(
                company: $run->company,
                to: $item->recipients,
                subject: $mail->subject,
                html: $mail->html,
                clientId: $item->client_id,
                category: $run->kind->value,
            ));
        } catch (MailNotSent $e) {
            Log::warning('Cobro: a collection mail was not sent.', ['run_item' => $item->id, 'exception' => $e->getMessage()]);
            $item->update(['status' => ItemStatus::Failed, 'error' => mb_substr($e->getMessage(), 0, 500)]);

            return;
        }

        $item->update([
            'status' => ItemStatus::Sent,
            'sent_at' => $sent->sentAt,
            'internet_message_id' => $sent->internetMessageId,
            'sent_message_id' => SentMessage::query()
                ->where('mailbox', $sent->mailbox)
                ->where('internet_message_id', $sent->internetMessageId)
                ->value('id'),
        ]);

        try {
            $activities->log(new NewActivity(
                clientId: $item->client_id,
                type: ActivityType::EmailSent,
                summary: __('cobro::envios.bitacora.enviado', ['tipo' => mb_strtolower($run->kind->label()), 'correos' => implode(', ', $item->recipients)]),
                details: $mail->subject,
                occurredAt: $sent->sentAt,
                metadata: ['cobro_run_id' => $run->id, 'cobro_run_item_id' => $item->id],
            ));
        } catch (RecordNotFound) {
            Log::info('Cobro: a mailed client no longer exists; the activity was not logged.', ['client_id' => $item->client_id]);
        }
    }
}
