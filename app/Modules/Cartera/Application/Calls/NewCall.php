<?php

namespace App\Modules\Cartera\Application\Calls;

use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What the collector sends to log a call, already validated for shape by
 * the form. LogCall still checks the permissions, the client, the dates,
 * the agreement and the invoices against the portfolio.
 */
final readonly class NewCall
{
    public const int PHONE_MAX_LENGTH = 40;

    public const int CONTACT_MAX_LENGTH = 150;

    /**
     * @param  int  $clientId  Core's client id (ClientSummary::$id).
     * @param  string  $phone  Number dialled or written to; up to 40 characters.
     * @param  string|null  $contactName  Who answered; blank means nobody in particular.
     * @param  CarbonImmutable|null  $followUpOn  Day to call again; required when the client asked for it.
     * @param  CarbonImmutable|null  $occurredAt  When the call happened; null = now.
     * @param  list<int>  $invoiceIds  Ids of the client's open invoices (Invoice::$id) the call was about.
     *
     * @throws InvalidArgumentException When the phone is blank or too long, the contact name is too long
     *                                  or an invoice id is not a positive integer.
     */
    public function __construct(
        public int $clientId,
        public CallChannel $channel,
        public string $phone,
        public ?string $contactName,
        public CallOutcome $outcome,
        public ?string $notes,
        public CallAgreement $agreement,
        public ?CarbonImmutable $followUpOn = null,
        public ?CarbonImmutable $occurredAt = null,
        public array $invoiceIds = [],
    ) {
        $length = mb_strlen(trim($phone));

        if ($length === 0 || $length > self::PHONE_MAX_LENGTH) {
            throw new InvalidArgumentException('A call needs a phone of 1 to '.self::PHONE_MAX_LENGTH.' characters.');
        }

        if ($contactName !== null && mb_strlen(trim($contactName)) > self::CONTACT_MAX_LENGTH) {
            throw new InvalidArgumentException('The contact name is longer than '.self::CONTACT_MAX_LENGTH.' characters.');
        }

        foreach ($invoiceIds as $invoiceId) {
            if ($invoiceId < 1) {
                throw new InvalidArgumentException("The invoice id [{$invoiceId}] must be a positive integer.");
            }
        }
    }
}
