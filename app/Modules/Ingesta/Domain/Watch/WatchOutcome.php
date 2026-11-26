<?php

namespace App\Modules\Ingesta\Domain\Watch;

/**
 * What WatchLoadSlot found, and did, for one company after a slot. The
 * values name the console lines in alerta.php (no_llego.consola).
 */
enum WatchOutcome: string
{
    /** An accepted load arrived after the slot. */
    case Arrived = 'llego';

    /** A final failure report of the slot arrived; its own alert went out. */
    case Reported = 'reportada';

    /** Nothing arrived and the alert was mailed now. */
    case Alerted = 'enviada';

    /** Nothing arrived and the alert of the slot had already been mailed. */
    case AlreadyAlerted = 'ya_enviada';

    /** Nothing arrived and there are no mail recipients. */
    case NoRecipients = 'sin_destinatarios';

    /** Nothing arrived and MailSender has no implementation. */
    case NoMailer = 'sin_mail_sender';
}
