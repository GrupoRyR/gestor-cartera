<?php

namespace App\Modules\Core\Contracts\Data;

/**
 * Everything the client file (ficha del cliente) shows besides invoices,
 * activities, commitments and e-mail replies, which come from their own
 * contracts.
 */
final readonly class ClientDetail
{
    /**
     * @param  float|null  $creditLimit  Credit limit (cupo) in Colombian pesos; null when SAP has none.
     * @param  string|null  $group  SAP business partner group.
     * @param  string|null  $city  Municipality.
     * @param  string|null  $paymentTerms  Payment terms as named in SAP, e.g. "30 días".
     * @param  list<Contact>  $contacts  Contacts from SAP, in SAP order.
     */
    public function __construct(
        public ClientSummary $summary,
        public ?float $creditLimit = null,
        public ?string $group = null,
        public ?string $city = null,
        public ?string $paymentTerms = null,
        public array $contacts = [],
    ) {}

    /**
     * Unique, non-empty contact e-mails: primary contacts first, then the
     * rest in contact order. Addresses are trimmed and compared without
     * regard to case; the first spelling wins.
     *
     * @return list<string>
     */
    public function emails(): array
    {
        $primary = array_filter($this->contacts, fn (Contact $contact): bool => $contact->isPrimary);
        $others = array_filter($this->contacts, fn (Contact $contact): bool => ! $contact->isPrimary);

        $emails = [];

        foreach ([...$primary, ...$others] as $contact) {
            $email = trim((string) $contact->email);

            if ($email !== '' && ! array_key_exists(mb_strtolower($email), $emails)) {
                $emails[mb_strtolower($email)] = $email;
            }
        }

        return array_values($emails);
    }
}
