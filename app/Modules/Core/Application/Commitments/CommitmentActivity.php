<?php

namespace App\Modules\Core\Application\Commitments;

use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;

/**
 * The entries a commitment leaves in its client's log (bitácora), with the
 * Spanish summaries of core::compromisos.resumen. Commitment entries carry
 * the metadata commitment_id, amount (pesos) and due_date ("Y-m-d").
 */
final class CommitmentActivity
{
    /** Length of activities.summary. */
    private const int SUMMARY_LENGTH = 255;

    /**
     * «Compromiso de pago por $ 2.500.000 para el 22/10/2026», with the notes
     * as details.
     */
    public static function created(CommitmentRecord $commitment): NewActivity
    {
        return self::entry($commitment, self::promise('creado', $commitment), $commitment->notes);
    }

    /**
     * «Compromiso actualizado: $ 3.000.000 para el 25/10/2026», with the new
     * amount and date, and the notes as details.
     */
    public static function updated(CommitmentRecord $commitment): NewActivity
    {
        return self::entry($commitment, self::promise('actualizado', $commitment), $commitment->notes);
    }

    /**
     * «Compromiso cumplido: $ 2.500.000 para el 22/10/2026», with the note
     * as details.
     */
    public static function fulfilled(CommitmentRecord $commitment, ?string $note): NewActivity
    {
        return self::entry($commitment, self::promise('cumplido', $commitment), $note);
    }

    /**
     * «Compromiso abonado: $ 1.000.000 de $ 2.500.000; queda $ 1.500.000»,
     * with the note as details and the metadata paid_amount and remaining
     * (pesos) besides the usual ones.
     */
    public static function partiallyPaid(CommitmentRecord $commitment, float $remaining, ?string $note): NewActivity
    {
        $summary = self::text('core::compromisos.resumen.abonado', [
            'pagado' => Money::format($commitment->paid_amount),
            'valor' => Money::format($commitment->amount),
            'saldo' => Money::format($remaining),
        ]);

        return self::entry($commitment, $summary, $note, [
            'paid_amount' => (float) $commitment->paid_amount,
            'remaining' => $remaining,
        ]);
    }

    /**
     * «Compromiso anulado: <reason>», cut to the length of the summary.
     */
    public static function cancelled(CommitmentRecord $commitment, string $reason): NewActivity
    {
        return self::entry($commitment, self::text('core::compromisos.resumen.anulado', ['motivo' => $reason]));
    }

    /**
     * «Compromiso incumplido: vencía el 14/10/2026»: the daily task broke it,
     * or a user did, with an optional note as details.
     */
    public static function broken(CommitmentRecord $commitment, ?string $note = null): NewActivity
    {
        return self::entry($commitment, self::text('core::compromisos.resumen.incumplido', [
            'fecha' => DateFormat::date($commitment->due_date),
        ]), $note);
    }

    /**
     * «Por el compromiso de pago: de «Vencida» a «Compromiso»»: the
     * commitment workflow changed the client's status.
     */
    public static function statusChange(int $clientId, ClientStatus $from, ClientStatus $to, int $commitmentId): NewActivity
    {
        return new NewActivity(
            clientId: $clientId,
            type: ActivityType::StatusChange,
            summary: self::text('core::compromisos.resumen.estado', ['anterior' => $from->label(), 'nuevo' => $to->label()]),
            metadata: ['from' => $from->value, 'to' => $to->value, 'commitment_id' => $commitmentId],
        );
    }

    /**
     * @param  array<string, float>  $metadata  Added after commitment_id, amount and due_date.
     */
    private static function entry(CommitmentRecord $commitment, string $summary, ?string $details = null, array $metadata = []): NewActivity
    {
        return new NewActivity(
            clientId: $commitment->client_id,
            type: ActivityType::Commitment,
            summary: $summary,
            details: $details,
            metadata: [
                'commitment_id' => $commitment->id,
                'amount' => (float) $commitment->amount,
                'due_date' => $commitment->due_date->toDateString(),
                ...$metadata,
            ],
        );
    }

    /**
     * A summary with the amount and the due date of the commitment.
     */
    private static function promise(string $key, CommitmentRecord $commitment): string
    {
        return self::text("core::compromisos.resumen.{$key}", [
            'valor' => Money::format($commitment->amount),
            'fecha' => DateFormat::date($commitment->due_date),
        ]);
    }

    /**
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace): string
    {
        $text = __($key, $replace);
        $text = is_string($text) ? $text : $key;

        if (mb_strlen($text) <= self::SUMMARY_LENGTH) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::SUMMARY_LENGTH - 1)).'…';
    }
}
