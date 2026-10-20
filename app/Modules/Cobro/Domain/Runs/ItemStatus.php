<?php

namespace App\Modules\Cobro\Domain\Runs;

/**
 * Where one client of a run stands in the delivery report.
 *
 * Pending → Sending → Sent → (Bounced, when the mailbox sync reads its
 * bounce) or Failed; NoEmail, Excluded and Skipped never send.
 */
enum ItemStatus: string
{
    case Pending = 'pendiente';
    case Sending = 'enviando';
    case Sent = 'enviado';
    case Bounced = 'rebotado';
    case NoEmail = 'sin_correo';
    case Excluded = 'excluido';
    case Skipped = 'omitido';
    case Failed = 'fallido';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Sending => 'Enviando',
            self::Sent => 'Enviado',
            self::Bounced => 'Rebotado',
            self::NoEmail => 'Sin correo',
            self::Excluded => 'Excluido',
            self::Skipped => 'Sin saldo al enviar',
            self::Failed => 'No se pudo enviar',
        };
    }

    /**
     * Tone of its <x-ui.chip>.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Bounced, self::Failed => 'danger',
            self::NoEmail => 'warning',
            self::Pending, self::Sending => 'info',
            self::Excluded, self::Skipped => 'neutral',
        };
    }

    /**
     * Still waiting for its send job.
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Sending;
    }
}
