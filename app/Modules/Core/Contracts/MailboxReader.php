<?php

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use InvalidArgumentException;

/**
 * Replies and bounces read from the company mailboxes and linked to clients
 * (by conversation, sent Message-ID or sender address).
 *
 * Implemented by Cobro (phase 1); tests use Tests\Fakes\FakeMailboxReader.
 * Reads what Cobro already synchronized from Graph; it never calls Graph
 * while rendering a page. Follows the scope rule of CarteraRepository.
 */
interface MailboxReader
{
    /**
     * Replies and bounces linked to the client, newest received first; empty
     * when the client does not exist or is out of scope.
     *
     * @param  int  $limit  At least 1.
     * @return list<InboundMail>
     *
     * @throws InvalidArgumentException When $limit is below 1.
     */
    public function forClient(int $clientId, int $limit = 20): array;

    /**
     * Messages not yet marked as read (replies and bounces, linked to a
     * client or not) of one company, or of every company in
     * CurrentCompany::scope() when null.
     */
    public function unreadCount(?CompanyCode $company = null): int;
}
