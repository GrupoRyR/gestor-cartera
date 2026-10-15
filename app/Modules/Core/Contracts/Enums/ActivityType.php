<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Kind of entry in a client's activity log (gestión / bitácora).
 */
enum ActivityType: string
{
    use HasOptions;

    /** Phone call; NewActivity::$result holds the outcome. */
    case Call = 'llamada';

    /** Free-text note. */
    case Note = 'nota';

    /** A payment commitment was created, changed, fulfilled or cancelled. */
    case Commitment = 'compromiso';

    /** A discount was offered to the client. */
    case DiscountOffered = 'descuento';

    /** An e-mail was sent to the client (collections, circularization). */
    case EmailSent = 'correo_enviado';

    /** The client replied, or a sent e-mail bounced. */
    case EmailReceived = 'correo_recibido';

    /** The client's status changed (manually or automatically). */
    case StatusChange = 'cambio_estado';

    /** Written by the platform itself, e.g. after a snapshot was loaded. */
    case System = 'sistema';

    public function label(): string
    {
        return match ($this) {
            self::Call => 'Llamada',
            self::Note => 'Nota',
            self::Commitment => 'Compromiso de pago',
            self::DiscountOffered => 'Descuento ofrecido',
            self::EmailSent => 'Correo enviado',
            self::EmailReceived => 'Correo recibido',
            self::StatusChange => 'Cambio de estado',
            self::System => 'Sistema',
        };
    }
}
