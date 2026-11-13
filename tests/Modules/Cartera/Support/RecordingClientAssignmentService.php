<?php

namespace Tests\Modules\Cartera\Support;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\ClientAssignmentService;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * Test double of Core's ClientAssignmentService, which has no fake in
 * Tests\Fakes. It looks the clients up in the cartera fake (so a client out
 * of scope is not found), refuses a manager who is not an assignee, records
 * each call and counts as changed the clients whose manager differs. It does
 * not change the clients in the fake.
 */
final class RecordingClientAssignmentService implements ClientAssignmentService
{
    /**
     * @var list<UserRef>
     */
    private array $assignees = [];

    /**
     * @var list<array{clientIds: list<int>, managerUserId: int|null}>
     */
    private array $assignments = [];

    /**
     * @var list<array{query: ClientQuery, managerUserId: int|null}>
     */
    private array $matchingAssignments = [];

    private bool $denied = false;

    public function __construct(private readonly CarteraRepository $cartera) {}

    public function withAssignees(UserRef ...$assignees): static
    {
        $this->assignees = array_values($assignees);

        return $this;
    }

    /**
     * Makes every method throw AuthorizationException, like a user without
     * the core.clients.assign permission.
     */
    public function deny(): static
    {
        $this->denied = true;

        return $this;
    }

    public function assignees(): array
    {
        $this->authorize();

        return $this->assignees;
    }

    public function assign(array $clientIds, ?int $managerUserId): int
    {
        $this->authorize();

        $clients = [];

        foreach (array_unique($clientIds) as $id) {
            $clients[] = $this->cartera->client($id)?->summary ?? throw RecordNotFound::for('client', $id);
        }

        $this->checkManager($managerUserId);
        $this->assignments[] = ['clientIds' => array_values($clientIds), 'managerUserId' => $managerUserId];

        return $this->changed($clients, $managerUserId);
    }

    public function assignMatching(ClientQuery $query, ?int $managerUserId): int
    {
        $this->authorize();
        $this->checkManager($managerUserId);
        $this->matchingAssignments[] = ['query' => $query, 'managerUserId' => $managerUserId];

        return $this->changed([...$this->cartera->allClients($query)], $managerUserId);
    }

    /**
     * @return list<array{clientIds: list<int>, managerUserId: int|null}>
     */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /**
     * @return list<array{query: ClientQuery, managerUserId: int|null}>
     */
    public function matchingAssignments(): array
    {
        return $this->matchingAssignments;
    }

    public function assertNothingAssigned(): void
    {
        Assert::assertSame([], $this->assignments, 'Some clients were assigned.');
        Assert::assertSame([], $this->matchingAssignments, 'The matching clients were assigned.');
    }

    private function authorize(): void
    {
        if ($this->denied) {
            throw new AuthorizationException('This user may not assign clients.');
        }
    }

    private function checkManager(?int $managerUserId): void
    {
        if ($managerUserId === null) {
            return;
        }

        foreach ($this->assignees as $assignee) {
            if ($assignee->id === $managerUserId) {
                return;
            }
        }

        throw new InvalidArgumentException("User [{$managerUserId}] may not look after these clients.");
    }

    /**
     * @param  list<ClientSummary>  $clients
     */
    private function changed(array $clients, ?int $managerUserId): int
    {
        return count(array_filter($clients, fn (ClientSummary $client): bool => $client->manager?->id !== $managerUserId));
    }
}
