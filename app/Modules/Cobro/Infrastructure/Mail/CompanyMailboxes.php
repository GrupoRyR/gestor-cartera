<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use UnexpectedValueException;

/**
 * The mailbox each company sends from and reads replies in (config
 * cobro.mailboxes), the only two mailboxes the Graph app may touch.
 */
final class CompanyMailboxes
{
    /**
     * @throws UnexpectedValueException When the company has no mailbox configured.
     */
    public function for(CompanyCode $company): string
    {
        $address = trim((string) config("cobro.mailboxes.{$company->value}"));

        if ($address === '') {
            throw new UnexpectedValueException("No Cobro mailbox is configured for {$company->value}.");
        }

        return mb_strtolower($address);
    }

    /**
     * Company => mailbox of every company with a mailbox, in CompanyCode order.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $mailboxes = [];

        foreach (CompanyCode::cases() as $company) {
            $address = trim((string) config("cobro.mailboxes.{$company->value}"));

            if ($address !== '') {
                $mailboxes[$company->value] = mb_strtolower($address);
            }
        }

        return $mailboxes;
    }
}
