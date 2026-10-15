<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\MailboxReader;
use Tests\Fakes\Concerns\QueriesInMemory;

/**
 * In-memory MailboxReader fed with add().
 *
 * With a CurrentCompany, unreadCount(null) counts the companies in its
 * scope; without one, every company. forClient() does not emulate the
 * client scope.
 */
final class FakeMailboxReader implements MailboxReader
{
    use QueriesInMemory;

    /**
     * @var list<InboundMail>
     */
    private array $mails = [];

    public function __construct(
        private readonly ?CurrentCompany $currentCompany = null,
    ) {}

    public function add(InboundMail ...$mails): static
    {
        $this->mails = [...$this->mails, ...array_values($mails)];

        return $this;
    }

    public function forClient(int $clientId, int $limit = 20): array
    {
        $this->guardAtLeastOne($limit, 'limit');

        $mails = array_filter($this->mails, fn (InboundMail $mail): bool => $mail->clientId === $clientId);

        // Newest first; mails received at the same moment keep the order they were added in.
        uksort($mails, fn (int $a, int $b): int => [$this->mails[$b]->receivedAt, $a] <=> [$this->mails[$a]->receivedAt, $b]);

        return array_slice(array_values($mails), 0, $limit);
    }

    public function unreadCount(?CompanyCode $company = null): int
    {
        $companies = $company === null ? $this->companiesInScope($this->currentCompany) : [$company];

        return count(array_filter(
            $this->mails,
            fn (InboundMail $mail): bool => ! $mail->read && ($companies === null || in_array($mail->company, $companies, true)),
        ));
    }
}
