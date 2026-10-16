<?php

namespace App\Modules\Cartera\Infrastructure\Mail;

use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\MailboxReader;
use Illuminate\Contracts\Container\Container;

/**
 * The e-mail replies and bounces of a client, read through Core's
 * MailboxReader contract. Cobro implements it; until that module is in the
 * application nothing is bound, and the client file says replies are not
 * available yet instead of failing.
 */
final class ClientReplies
{
    public function __construct(private readonly Container $container) {}

    /**
     * Whether a module reads the company mailboxes in this installation.
     */
    public function available(): bool
    {
        return $this->container->bound(MailboxReader::class);
    }

    /**
     * Newest first; empty when the reader is missing or the client has none.
     *
     * @return list<InboundMail>
     */
    public function forClient(int $clientId, int $limit = 10): array
    {
        if (! $this->available()) {
            return [];
        }

        return $this->container->make(MailboxReader::class)->forClient($clientId, $limit);
    }
}
