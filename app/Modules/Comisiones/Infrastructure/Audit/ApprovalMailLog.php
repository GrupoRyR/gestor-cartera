<?php

namespace App\Modules\Comisiones\Infrastructure\Audit;

use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Support\Formatting\DateFormat;
use Illuminate\Support\Arr;

/**
 * What happened to the approval mail of a version, read back from the
 * module's audit trail: the latest comisiones.settlement.mailed or
 * comisiones.settlement.mail_failed of that version and of the revision
 * that was approved. Shown on the period screen.
 */
final class ApprovalMailLog
{
    public const string SENT = 'comisiones.settlement.mailed';

    public const string FAILED = 'comisiones.settlement.mail_failed';

    /**
     * The line for an approved or closed version, or null while there is
     * nothing to say (a draft, or a mail still in the queue).
     *
     * @param  int|null  $recordId  The stored row of the version (the audit subject).
     * @return array{sent: bool, text: string}|null
     */
    public static function line(Settlement $settlement, ?int $recordId): ?array
    {
        if ($recordId === null || $settlement->status === SettlementStatus::Draft) {
            return null;
        }

        $entry = AuditRecord::query()
            ->whereIn('evento', [self::SENT, self::FAILED])
            ->where('sujeto_tipo', 'liquidacion')
            ->where('sujeto_id', (string) $recordId)
            ->orderByDesc('id')
            ->get()
            ->first(fn (AuditRecord $entry): bool => (int) ($entry->contexto['revision'] ?? 0) === $settlement->revision);

        if ($entry === null) {
            return null;
        }

        if ($entry->evento === self::SENT) {
            /** @var list<string> $recipients */
            $recipients = (array) ($entry->contexto['destinatarios'] ?? []);

            return ['sent' => true, 'text' => __('comisiones::liquidacion.correo.enviado', [
                'destinatarios' => Arr::join($recipients, ', ', ' y '),
                'fecha' => DateFormat::dateTime($entry->created_at),
            ])];
        }

        $reason = (string) ($entry->contexto['fallo'] ?? 'error');
        $key = 'comisiones::liquidacion.correo.motivos.'.$reason;
        $text = __($key);

        return ['sent' => false, 'text' => __('comisiones::liquidacion.correo.fallido', [
            'motivo' => is_string($text) && $text !== $key ? $text : __('comisiones::liquidacion.correo.motivos.error'),
        ])];
    }
}
