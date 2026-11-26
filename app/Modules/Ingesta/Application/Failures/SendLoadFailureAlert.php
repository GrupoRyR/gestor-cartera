<?php

namespace App\Modules\Ingesta\Application\Failures;

use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use App\Support\Formatting\DateFormat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails ingesta.alert_recipients that a load failed for good: queued by
 * RecordLoadFailure for a new final report. It goes out from the Segurex
 * mailbox, the platform's, like IT's other notices (category "alerta"),
 * and explains the cause in plain words with the stored message, the
 * stage, the attempt and when it happened.
 *
 * One alert per company, dataset and slot: the job claims the slot with
 * an insert-or-ignore into ingesta_load_alerts (kind "falla") and sends
 * nothing when the row already exists, so a second final report for the
 * same slot (another run, the Bases SAP reader) mails no one. The claim
 * and the send share one transaction: when Graph refuses the mail
 * (MailNotSent, nothing was sent) or the worker dies mid-send, the claim
 * rolls back with it and a retry can still send. That is why this job,
 * unlike the other notices, retries: a refused mail is retried after 1 and
 * 5 minutes. The cost is a short transaction around the Graph call, and a
 * second mail in the rare case the commit fails after Graph accepted it;
 * for an alert, twice beats never.
 *
 * Without recipients it only logs a warning, and without a MailSender
 * implementation (Cobro provides it) it logs an error; neither claims the
 * slot, so a later final report for it can still alert.
 *
 * Before the mail it puts the failure in the bell (LoadNotices, through
 * SlotAlerts), as a step of its own: its own claim (kind "campana") and
 * AvisoService::send() in their own transaction, committed before the mail
 * is tried. So the bell
 * does not wait for Graph or for the mail recipients, a refused mail rolls
 * back only the mail claim, and the retry finds the bell claimed and does
 * not ring it again. A bell that fails is logged and rolled back, and the
 * mail still goes; a retry of the job would try the bell again. With no
 * user to notify, or no AvisoService, it only logs and claims nothing.
 */
final class SendLoadFailureAlert implements ShouldQueue
{
    use Queueable;

    public const string TIMEZONE = 'America/Bogota';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $failureId,
    ) {}

    public function handle(): void
    {
        $failure = LoadFailureRecord::query()->findOrFail($this->failureId);
        $context = ['failure_id' => $failure->id, 'company' => $failure->company->value, 'dataset' => $failure->dataset->value];

        $alerts = app(SlotAlerts::class);
        $alerts->ringBell(
            $failure->company,
            $failure->dataset,
            $failure->slot_at,
            $failure->id,
            fn (array $recipients): Aviso => app(LoadNotices::class)->forFailure($failure, $this->explanation($failure), $recipients),
            $context,
        );

        /** @var list<string> $recipients */
        $recipients = (array) config('ingesta.alert_recipients', []);

        if ($recipients === []) {
            Log::warning('Ingesta: a load failed for good and no alert recipients are configured.', $context);

            return;
        }

        if (! app()->bound(MailSender::class)) {
            Log::error('Ingesta: the load failure alert could not be sent: MailSender has no implementation.', $context);

            return;
        }

        $mail = $this->mail($failure, $recipients);

        DB::transaction(function () use ($alerts, $failure, $mail, $context): void {
            if (! $alerts->claim($failure->company, $failure->dataset, $failure->slot_at, LoadAlertKind::Falla, $failure->id)) {
                Log::info('Ingesta: the load failure alert of this slot was already sent.', $context);

                return;
            }

            try {
                app(MailSender::class)->send($mail);
            } catch (MailNotSent $e) {
                Log::error('Ingesta: the load failure alert could not be sent.', [...$context, 'attempt' => $this->attempts(), 'exception' => $e]);

                throw $e;
            }
        });
    }

    /**
     * Every attempt failed: the slot stays unclaimed and nobody was told.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Ingesta: the load failure alert was not sent after its retries.', ['failure_id' => $this->failureId, 'exception' => $exception]);
    }

    /**
     * @param  list<string>  $recipients
     */
    private function mail(LoadFailureRecord $failure, array $recipients): OutgoingMail
    {
        $slot = $failure->slot_at->setTimezone(self::TIMEZONE);
        $stage = __('ingesta::alerta.falla.etapas.'.$failure->stage->value);
        $names = [
            'lote' => mb_strtolower($failure->dataset->label()),
            'empresa' => $failure->company->label(),
            'hora' => $slot->format('H:i'),
            'fecha' => DateFormat::date($slot),
        ];
        $explanation = $this->explanation($failure);

        return new OutgoingMail(
            company: CompanyCode::SGX,
            to: $recipients,
            subject: __('ingesta::alerta.falla.asunto', $names),
            html: view('ingesta::alerta.falla', [
                'names' => $names,
                'failure' => $failure,
                'explanation' => $explanation,
                'stage' => $stage,
                'url' => route('ingesta.index'),
            ])->render(),
            category: 'alerta',
        );
    }

    /**
     * What happened and what to do, in plain words, for the failure's cause:
     * the mail's explanation and the bell's body.
     */
    private function explanation(LoadFailureRecord $failure): string
    {
        $text = match ($failure->cause) {
            FailureCause::Vpn, FailureCause::HanaRoute => __('ingesta::alerta.falla.causas.conexion', ['attempts' => $failure->attempt]),
            FailureCause::Network => __('ingesta::alerta.falla.causas.red'),
            FailureCause::Credentials => __('ingesta::alerta.falla.causas.credenciales'),
            FailureCause::Data => __('ingesta::alerta.falla.causas.datos'),
            default => __('ingesta::alerta.falla.causas.otra', ['etapa' => __('ingesta::alerta.falla.etapas.'.$failure->stage->value)]),
        };

        return is_string($text) ? $text : '';
    }
}
