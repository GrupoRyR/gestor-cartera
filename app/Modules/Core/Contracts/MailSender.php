<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Data\SentMail;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;

/**
 * Sends e-mail through Microsoft Graph (sendMail) from the mailbox of a
 * company: segurex.info@… for SGX and derivados.info@derivadoscol.com for
 * SDC. The application only has Graph permissions on those two mailboxes.
 *
 * Implemented by Cobro (phase 1). Tests never reach Graph nor use real
 * credentials: they use Tests\Fakes\FakeMailSender.
 *
 * When OutgoingMail::$clientId is set, the implementation keeps the sent
 * ids so MailboxReader can link replies and bounces to the client. It does
 * not write the activity log: callers that mail a client log
 * ActivityType::EmailSent themselves.
 */
interface MailSender
{
    /**
     * Sends the mail synchronously; queue the calling job for bulk sends.
     *
     * @throws MailNotSent When Graph does not accept the mail; nothing was sent.
     */
    public function send(OutgoingMail $mail): SentMail;
}
