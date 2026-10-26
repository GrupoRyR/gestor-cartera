<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Domain\Copies\CopyRecipients;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Data\SentMail;
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
 * send time and hands it to MailSender (with the carbon copies the
 * definition rendered, minus the item's own recipients and any address
 * outside config cobro.copy_domains), then records the outcome on the
 * item and the EmailSent activity of the client.
 *
 * The job first claims the item (pending → enviando in one UPDATE), so a
 * retried or duplicated job never mails a client twice; every failure ends
 * in the item (fallido, with a Spanish reason; the detail goes to the log)
 * instead of a retry. Once MailSender accepted the mail the item is sent,
 * whatever happens to the bookkeeping after it. The "cobro-mail" rate
 * limiter keeps the sends under Exchange's per-minute limit: a limited job
 * goes back to the queue, for up to RETRY_HOURS. An item left enviando by
 * a killed worker is closed by RunFinisher::expireStale().
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
            $result = $this->send($item, $definitions, $companies, $sender);

            if ($result !== null) {
                $this->recordSent($item, $result[0], $result[1], $activities);
            }
        } finally {
            $finisher->finishIfDone($item->run_id);
        }
    }

    /**
     * Renders and hands the mail to MailSender; the receipt and the
     * subject, or null when the item ended without a send (skipped or
     * failed).
     *
     * @return array{SentMail, string}|null
     */
    private function send(RunItem $item, RunDefinitions $definitions, CurrentCompany $companies, MailSender $sender): ?array
    {
        $run = $item->run;

        try {
            $definition = $definitions->for($run->kind);
            $notice = $definition->notice($item->client_id);

            if ($notice === null) {
                $item->update(['status' => ItemStatus::Skipped]);

                return null;
            }

            $mail = $definition->render($companies->of($run->company), $notice);

            $sent = $sender->send(new OutgoingMail(
                company: $run->company,
                to: $item->recipients,
                subject: $mail->subject,
                html: $mail->html,
                cc: $this->copies($item, CopyRecipients::excluding($mail->cc, $item->recipients)),
                clientId: $item->client_id,
                category: $run->kind->value,
            ));

            return [$sent, $mail->subject];
        } catch (MailNotSent $e) {
            Log::warning('Cobro: a collection mail was not sent.', ['run_item' => $item->id, 'exception' => $e->getMessage()]);
            $item->update(['status' => ItemStatus::Failed, 'error' => self::reason($e)]);
        } catch (Throwable $e) {
            report($e);
            $item->update(['status' => ItemStatus::Failed, 'error' => __('cobro::envios.errores.inesperado')]);
        }

        return null;
    }

    /**
     * The copies of the company domains only. Copies saved before the rule,
     * or a client's advisor with another domain, are dropped with one
     * warning that names only their domains (no third-party addresses).
     *
     * @param  list<string>  $cc
     * @return list<string>
     */
    private function copies(RunItem $item, array $cc): array
    {
        $allowed = CopyRecipients::allowed($cc, array_values(array_map(strval(...), (array) config('cobro.copy_domains', []))));
        $dropped = array_values(array_diff($cc, $allowed));

        if ($dropped !== []) {
            Log::warning('cobro.copies.dropped', [
                'company' => $item->run->company->value,
                'run_item' => $item->id,
                'count' => count($dropped),
                'domains' => array_values(array_unique(array_map(CopyRecipients::domain(...), $dropped))),
            ]);
        }

        return $allowed;
    }

    /**
     * Marks the item sent and logs the client activity. The mail already
     * left: a failure here is reported, never turned into "fallido".
     */
    private function recordSent(RunItem $item, SentMail $sent, string $subject, GestionLogger $activities): void
    {
        $item->update(['status' => ItemStatus::Sent, 'sent_at' => $sent->sentAt, 'internet_message_id' => $sent->internetMessageId]);

        try {
            $item->update([
                'sent_message_id' => SentMessage::query()
                    ->where('mailbox', $sent->mailbox)
                    ->where('internet_message_id', $sent->internetMessageId)
                    ->value('id'),
            ]);

            $activities->log(new NewActivity(
                clientId: $item->client_id,
                type: ActivityType::EmailSent,
                summary: __('cobro::envios.bitacora.enviado', ['tipo' => mb_strtolower($item->run->kind->label()), 'correos' => implode(', ', $item->recipients)]),
                details: $subject,
                occurredAt: $sent->sentAt,
                metadata: ['cobro_run_id' => $item->run_id, 'cobro_run_item_id' => $item->id],
            ));
        } catch (RecordNotFound) {
            Log::info('Cobro: a mailed client no longer exists; the activity was not logged.', ['client_id' => $item->client_id]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * What the delivery report says about a refused mail, in Spanish; the
     * English detail of the exception stays in the log.
     */
    public static function reason(MailNotSent $e): string
    {
        $graph = $e->getPrevious();

        while ($graph !== null && ! $graph instanceof GraphRequestFailed) {
            $graph = $graph->getPrevious();
        }

        if (! $graph instanceof GraphRequestFailed) {
            return (string) __('cobro::envios.errores.no_aceptado');
        }

        return (string) match (true) {
            $graph->graphCode === GraphRequestFailed::NOT_CONFIGURED => __('cobro::envios.errores.sin_configurar'),
            $graph->status === null => __('cobro::envios.errores.sin_respuesta'),
            $graph->status === 429 => __('cobro::envios.errores.limite'),
            in_array($graph->status, [401, 403], true) => __('cobro::envios.errores.permisos'),
            $graph->graphCode === 'ErrorInvalidRecipients' => __('cobro::envios.errores.destinatario'),
            default => __('cobro::envios.errores.codigo', ['codigo' => $graph->status]),
        };
    }
}
