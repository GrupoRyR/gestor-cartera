<?php

namespace App\Modules\Comisiones\Infrastructure\Mail;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Settlement\EloquentSettlements;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Sends the approval mail of one settlement version on the queue, so the
 * approver does not wait for the PDF and Microsoft Graph. Queued by
 * SettlementActionController::approve once the approval is stored.
 *
 * It runs once ($tries = 1): ApprovalMail already turns every failure into
 * an audited comisiones.settlement.mail_failed, and a retry after a send
 * Graph accepted would mail Marcela and Mónica twice. A worker killed
 * mid-job is not retried either: the database queue counts that attempt
 * and fails the job, and failed() audits it. The approval never depends on
 * this job.
 */
final class SendApprovalMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @param  string  $period  "YYYY-MM".
     * @param  int  $revision  The revision that was approved.
     */
    public function __construct(
        public readonly string $period,
        public readonly int $version,
        public readonly int $revision,
        public readonly int $approverId,
    ) {}

    public function handle(EloquentSettlements $settlements, ApprovalMail $mail): void
    {
        $settlement = $settlements->version(Period::fromString($this->period), $this->version);
        $approver = User::query()->find($this->approverId);

        if ($settlement === null || $approver === null) {
            throw new RuntimeException("The approved settlement {$this->period} v{$this->version} or its approver no longer exists.");
        }

        $mail->send($settlement, $approver, $this->revision);
    }

    /**
     * Something failed before ApprovalMail could answer (the version or the
     * approver could not be loaded, the worker died): logged and audited
     * like any other mail that did not go out.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Comisiones: the approval mail job failed.', ['period' => $this->period, 'version' => $this->version, 'exception' => $exception]);

        $period = Period::fromString($this->period);

        app(AuditTrail::class)->record(
            event: 'comisiones.settlement.mail_failed',
            subjectType: 'liquidacion',
            subjectId: app(EloquentSettlements::class)->record($period, $this->version)?->id,
            context: [
                'version' => $this->version,
                'revision' => $this->revision,
                'destinatarios' => ApprovalMail::recipients(),
                'fallo' => 'error',
            ],
            period: $this->period,
            user: User::query()->find($this->approverId),
        );
    }
}
