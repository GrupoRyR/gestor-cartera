<?php

namespace App\Modules\Ingesta\Application\Failures;

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\LoadAlertKind;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadAlertRecord;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use App\Support\Formatting\DateFormat;
use Carbon\CarbonImmutable;
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
 */
final class SendLoadFailureAlert implements ShouldQueue
{
    use Queueable;

    private const string TIMEZONE = 'America/Bogota';

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

        DB::transaction(function () use ($failure, $mail, $context): void {
            if (! $this->claim($failure)) {
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
     * Writes the slot's "falla" alert row; false when it already exists.
     */
    private function claim(LoadFailureRecord $failure): bool
    {
        $timezone = (string) config('app.timezone');

        return LoadAlertRecord::query()->insertOrIgnore([
            'company' => $failure->company->value,
            'dataset' => $failure->dataset->value,
            'slot_at' => $failure->slot_at->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'kind' => LoadAlertKind::Falla->value,
            'load_failure_id' => $failure->id,
            'created_at' => CarbonImmutable::now($timezone)->format('Y-m-d H:i:s'),
        ]) === 1;
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

        $explanation = match ($failure->cause) {
            FailureCause::Vpn, FailureCause::HanaRoute => __('ingesta::alerta.falla.causas.conexion', ['attempts' => $failure->attempt]),
            FailureCause::Network => __('ingesta::alerta.falla.causas.red'),
            FailureCause::Credentials => __('ingesta::alerta.falla.causas.credenciales'),
            FailureCause::Data => __('ingesta::alerta.falla.causas.datos'),
            default => __('ingesta::alerta.falla.causas.otra', ['etapa' => $stage]),
        };

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
}
