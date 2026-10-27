<?php

namespace App\Modules\Cobro\Domain\Mail;

/**
 * What BounceDetector::classify() reads from an inbound message, besides
 * its body:
 *
 * - the sender address;
 * - its MAPI message class (PidTagMessageClass: "IPM.Note",
 *   "REPORT.IPM.Note.NDR"), null when Graph did not return it;
 * - how Exchange authenticated it (header X-MS-Exchange-Organization-AuthAs:
 *   Internal, Anonymous, External or Partner), null when unknown;
 * - our domains: the company mailboxes' and the Microsoft 365 tenant's
 *   (gruporyr.onmicrosoft.com), which count only here.
 *
 * Addresses and domains are kept in lower case.
 */
final readonly class InboundEnvelope
{
    public string $sender;

    /**
     * @var list<string>
     */
    public array $ownDomains;

    /**
     * @param  list<string>  $ownDomains
     */
    public function __construct(
        string $sender,
        public ?string $messageClass,
        public ?string $authAs,
        array $ownDomains,
    ) {
        $this->sender = mb_strtolower(trim($sender));
        $this->ownDomains = array_values(array_unique(array_filter(
            array_map(fn (string $domain): string => mb_strtolower(trim($domain)), $ownDomains),
            fn (string $domain): bool => $domain !== '',
        )));
    }

    /**
     * The sender before its last "@" ("" without one).
     */
    public function localPart(): string
    {
        $at = mb_strrpos($this->sender, '@');

        return $at === false ? '' : mb_substr($this->sender, 0, $at);
    }

    /**
     * The sender after its last "@" ("" without one).
     */
    public function domain(): string
    {
        $at = mb_strrpos($this->sender, '@');

        return $at === false ? '' : mb_substr($this->sender, $at + 1);
    }

    /**
     * Whether the sender's domain is exactly one of our domains (a
     * subdomain does not count).
     */
    public function fromOwnDomain(): bool
    {
        return $this->domain() !== '' && in_array($this->domain(), $this->ownDomains, true);
    }

    /**
     * Whether Exchange authenticated the message as sent inside the
     * organization.
     */
    public function internal(): bool
    {
        return $this->authAs !== null && strcasecmp(trim($this->authAs), 'Internal') === 0;
    }
}
