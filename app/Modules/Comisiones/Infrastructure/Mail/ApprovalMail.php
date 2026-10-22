<?php

namespace App\Modules\Comisiones\Infrastructure\Mail;

use App\Models\User;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Report\ConsolidatedPdf;
use App\Modules\Comisiones\Infrastructure\Report\ConsolidatedReport;
use App\Modules\Comisiones\Infrastructure\Report\Labels;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use App\Modules\Core\Contracts\Data\MailAttachment;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mails an approved settlement to comisiones.approval_mail.recipients:
 * a short summary and the consolidated report of the approved version as
 * a PDF, sent through Core's MailSender from the Segurex mailbox (category
 * "comisiones_aprobacion").
 *
 * Sending is best effort: the approval is already stored, so a missing
 * sender, no recipients, a mail Graph refuses or a PDF that fails to
 * render never throw. Each outcome is audited as
 * comisiones.settlement.mailed or comisiones.settlement.mail_failed, and
 * send() tells the caller whether the mail went out.
 */
final readonly class ApprovalMail
{
    public const string CATEGORY = 'comisiones_aprobacion';

    public function __construct(
        private ConsolidatedPdf $pdf,
        private AuditTrail $audit,
        private EloquentSettlements $settlements,
    ) {}

    /**
     * The configured recipients, without blanks or repeats.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        $recipients = array_map(fn (mixed $address): string => trim((string) $address), (array) config('comisiones.approval_mail.recipients', []));

        return array_values(array_unique(array_filter($recipients, fn (string $address): bool => $address !== '')));
    }

    /**
     * @return bool Whether the mail was sent.
     */
    public function send(Settlement $settlement, User $approver): bool
    {
        $recipients = self::recipients();

        if ($recipients === []) {
            Log::warning('Comisiones: the approval mail has no recipients configured.', ['period' => $settlement->period->key()]);

            return $this->failed($settlement, $approver, $recipients, 'sin_destinatarios');
        }

        if (! app()->bound(MailSender::class)) {
            Log::error('Comisiones: the approval mail could not be sent, MailSender has no implementation.', ['period' => $settlement->period->key()]);

            return $this->failed($settlement, $approver, $recipients, 'sin_mail_sender');
        }

        try {
            app(MailSender::class)->send($this->mail($settlement, $approver, $recipients));
        } catch (MailNotSent $exception) {
            Log::error('Comisiones: the approval mail could not be sent.', ['period' => $settlement->period->key(), 'exception' => $exception]);

            return $this->failed($settlement, $approver, $recipients, 'no_enviado');
        } catch (Throwable $exception) {
            // A bug while building the mail (the PDF, the view) must not undo the approval either.
            report($exception);

            return $this->failed($settlement, $approver, $recipients, 'error');
        }

        $this->record('comisiones.settlement.mailed', $settlement, $approver, $recipients);

        return true;
    }

    /**
     * @param  list<string>  $recipients
     */
    private function mail(Settlement $settlement, User $approver, array $recipients): OutgoingMail
    {
        $report = ConsolidatedReport::of($settlement);
        $month = Labels::month($settlement->period);
        $fileName = $report->fileName('pdf');

        return new OutgoingMail(
            company: CompanyCode::SGX,
            to: $recipients,
            subject: __('comisiones::correos.aprobacion.asunto', ['mes' => $month]),
            html: view('comisiones::correos.aprobacion', [
                'report' => $report,
                'month' => $month,
                'approver' => $approver,
                'fileName' => $fileName,
                'url' => route('comisiones.periodos.show', $settlement->period->key()),
            ])->render(),
            attachments: [new MailAttachment($fileName, 'application/pdf', $this->pdf->render($report, $approver->name))],
            category: self::CATEGORY,
        );
    }

    /**
     * @param  list<string>  $recipients
     */
    private function failed(Settlement $settlement, User $approver, array $recipients, string $reason): bool
    {
        $this->record('comisiones.settlement.mail_failed', $settlement, $approver, $recipients, $reason);

        return false;
    }

    /**
     * @param  list<string>  $recipients
     */
    private function record(string $event, Settlement $settlement, User $approver, array $recipients, ?string $failure = null): void
    {
        $this->audit->record(
            event: $event,
            subjectType: 'liquidacion',
            subjectId: $this->settlements->record($settlement->period, $settlement->version)?->id,
            context: array_filter([
                'version' => $settlement->version,
                'revision' => $settlement->revision,
                'destinatarios' => $recipients,
                'fallo' => $failure,
            ], fn (mixed $value): bool => $value !== null),
            period: $settlement->period->key(),
            user: $approver,
        );
    }
}
