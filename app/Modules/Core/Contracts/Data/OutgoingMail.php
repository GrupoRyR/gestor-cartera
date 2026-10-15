<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use InvalidArgumentException;

/**
 * An e-mail to send with MailSender from a company mailbox.
 */
final readonly class OutgoingMail
{
    /**
     * @param  CompanyCode  $company  Selects the sender mailbox (and the brand of the template).
     * @param  list<string>  $to  Recipient addresses; at least one, none blank.
     * @param  string  $html  Complete HTML body, already rendered.
     * @param  list<string>  $cc  Carbon-copy addresses.
     * @param  string|null  $replyTo  Reply-to address; null = the company mailbox.
     * @param  list<MailAttachment>  $attachments  Files to attach.
     * @param  int|null  $clientId  Client the mail is about, to link replies and bounces back to it.
     * @param  string  $category  What the mail is for, for reports and logs, e.g. cobro_semanal,
     *                            circularizacion, prueba or alerta.
     *
     * @throws InvalidArgumentException When there is no recipient or a recipient is blank.
     */
    public function __construct(
        public CompanyCode $company,
        public array $to,
        public string $subject,
        public string $html,
        public array $cc = [],
        public ?string $replyTo = null,
        public array $attachments = [],
        public ?int $clientId = null,
        public string $category = 'general',
    ) {
        if ($to === []) {
            throw new InvalidArgumentException('An outgoing mail needs at least one recipient.');
        }

        foreach ($to as $address) {
            if (trim($address) === '') {
                throw new InvalidArgumentException('An outgoing mail cannot have a blank recipient.');
            }
        }
    }
}
