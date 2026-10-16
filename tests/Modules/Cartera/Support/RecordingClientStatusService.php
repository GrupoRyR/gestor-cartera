<?php

namespace Tests\Modules\Cartera\Support;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\ClientStatusService;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Assert;

/**
 * Test double of Core's ClientStatusService, which has no fake in
 * Tests\Fakes yet. It looks the client up in the cartera fake (so a client
 * out of scope is not found), records each change and returns the client
 * with its new status.
 */
final class RecordingClientStatusService implements ClientStatusService
{
    /**
     * @var list<array{clientId: int, status: ClientStatus, note: string|null}>
     */
    private array $changes = [];

    private bool $denied = false;

    public function __construct(private readonly CarteraRepository $cartera) {}

    /**
     * Makes change() throw AuthorizationException, like a user without the
     * core.clients.change_status permission.
     */
    public function deny(): static
    {
        $this->denied = true;

        return $this;
    }

    public function change(int $clientId, ClientStatus $status, ?string $note = null): ClientSummary
    {
        if ($this->denied) {
            throw new AuthorizationException('This user may not change client statuses.');
        }

        $client = $this->cartera->client($clientId)?->summary ?? throw RecordNotFound::for('client', $clientId);

        $this->changes[] = ['clientId' => $clientId, 'status' => $status, 'note' => $note === null || trim($note) === '' ? null : $note];

        return new ClientSummary(
            id: $client->id,
            company: $client->company,
            code: $client->code,
            name: $client->name,
            taxId: $client->taxId,
            status: $status,
            balance: $client->balance,
            overdue: $client->overdue,
            maxDaysOverdue: $client->maxDaysOverdue,
            advisor: $client->advisor,
            manager: $client->manager,
            lastActivityAt: $client->lastActivityAt,
        );
    }

    /**
     * @return list<array{clientId: int, status: ClientStatus, note: string|null}>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function assertChanged(int $clientId, ClientStatus $status, ?string $note = null): void
    {
        Assert::assertContains(
            ['clientId' => $clientId, 'status' => $status, 'note' => $note],
            $this->changes,
            "Client {$clientId} was not moved to {$status->value}.",
        );
    }

    public function assertNothingChanged(): void
    {
        Assert::assertSame([], $this->changes, 'A client status was changed.');
    }
}
