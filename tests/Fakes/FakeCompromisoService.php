<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\CommitmentQuery;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Tests\Fakes\Concerns\QueriesInMemory;

/**
 * In-memory CompromisoService.
 *
 * Clients come from knowClient() or, when given, from the CarteraRepository
 * fake, whose scope it honours: a commitment of a client that exists but is
 * out of scope behaves as missing. Commitments of clients nobody knows stay
 * visible. denyAuthorization() makes every write throw
 * AuthorizationException. Seed existing commitments with add(); only
 * create() counts for assertCreated().
 */
final class FakeCompromisoService implements CompromisoService
{
    use QueriesInMemory;

    /**
     * @var array<int, Commitment>
     */
    private array $commitments = [];

    /**
     * Ids of the commitments made through create().
     *
     * @var list<int>
     */
    private array $created = [];

    /**
     * @var array<int, array{name: string, company: CompanyCode, advisorCode: int|null}>
     */
    private array $clients = [];

    /**
     * @var array<int, string>
     */
    private array $cancellationReasons = [];

    private int $nextId = 1;

    private UserRef $user;

    private bool $denied = false;

    public function __construct(
        private readonly ?CarteraRepository $cartera = null,
        private readonly ?CurrentCompany $currentCompany = null,
    ) {
        $this->user = new UserRef(1, 'Usuario de prueba', 'prueba@example.com');
    }

    /**
     * The user that creates the next commitments.
     */
    public function actingAs(UserRef $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Makes a client available to create() without the cartera fake.
     */
    public function knowClient(int $id, string $name, CompanyCode $company = CompanyCode::SGX, ?int $advisorCode = null): static
    {
        $this->clients[$id] = ['name' => $name, 'company' => $company, 'advisorCode' => $advisorCode];

        return $this;
    }

    /**
     * Seeds existing commitments, e.g. Broken ones, without counting them as created.
     */
    public function add(Commitment ...$commitments): static
    {
        foreach ($commitments as $commitment) {
            $this->commitments[$commitment->id] = $commitment;
            $this->nextId = max($this->nextId, $commitment->id + 1);
        }

        return $this;
    }

    /**
     * Every write throws AuthorizationException while denied.
     */
    public function denyAuthorization(bool $deny = true): static
    {
        $this->denied = $deny;

        return $this;
    }

    public function create(NewCommitment $commitment): Commitment
    {
        $client = $this->resolveClient($commitment->clientId);
        $this->authorize();
        $this->guardAmount($commitment->amount);

        $created = new Commitment(
            id: $this->nextId++,
            company: $client['company'],
            clientId: $commitment->clientId,
            clientName: $client['name'],
            advisorCode: $client['advisorCode'],
            amount: $commitment->amount,
            dueDate: $commitment->dueDate,
            status: CommitmentStatus::Pending,
            notes: $commitment->notes,
            createdBy: $this->user,
            createdAt: CarbonImmutable::now(),
        );

        $this->commitments[$created->id] = $created;
        $this->created[] = $created->id;

        return $created;
    }

    public function update(int $commitmentId, CommitmentChanges $changes): Commitment
    {
        $commitment = $this->findOrFail($commitmentId);
        $this->authorize();

        if ($changes->amount !== null) {
            $this->guardAmount($changes->amount);
        }

        if ($commitment->status !== CommitmentStatus::Pending) {
            throw new DomainException("Commitment {$commitmentId} is {$commitment->status->value}; only pending commitments change.");
        }

        return $this->replace($commitment, [
            'dueDate' => $changes->dueDate ?? $commitment->dueDate,
            'amount' => $changes->amount ?? $commitment->amount,
            'notes' => $changes->notes ?? $commitment->notes,
        ]);
    }

    public function markFulfilled(int $commitmentId, ?string $note = null): Commitment
    {
        $commitment = $this->findOrFail($commitmentId);
        $this->authorize();
        $this->guardNotFinal($commitment);

        return $this->replace($commitment, [
            'status' => CommitmentStatus::Fulfilled,
            'fulfilledAt' => CarbonImmutable::now(),
        ]);
    }

    public function cancel(int $commitmentId, string $reason): Commitment
    {
        $commitment = $this->findOrFail($commitmentId);
        $this->authorize();

        if (trim($reason) === '') {
            throw new InvalidArgumentException('A cancelled commitment needs a reason.');
        }

        $this->guardNotFinal($commitment);
        $this->cancellationReasons[$commitmentId] = $reason;

        return $this->replace($commitment, ['status' => CommitmentStatus::Cancelled]);
    }

    public function find(int $commitmentId): ?Commitment
    {
        $commitment = $this->commitments[$commitmentId] ?? null;

        return $commitment !== null && ! $this->outOfScope($commitment->clientId) ? $commitment : null;
    }

    public function paginate(CommitmentQuery $query, int $perPage = 25): LengthAwarePaginator
    {
        $companies = $this->companiesInScope($this->currentCompany, $query->companies);

        $matching = array_values(array_filter(
            $this->commitments,
            fn (Commitment $commitment): bool => ! $this->outOfScope($commitment->clientId)
                && ($companies === null || in_array($commitment->company, $companies, true))
                && ($query->clientId === null || $commitment->clientId === $query->clientId)
                && ($query->advisorCode === null || $commitment->advisorCode === $query->advisorCode)
                && ($query->statuses === [] || in_array($commitment->status, $query->statuses, true))
                && ($query->dueFrom === null || $commitment->dueDate->toDateString() >= $query->dueFrom->toDateString())
                && ($query->dueTo === null || $commitment->dueDate->toDateString() <= $query->dueTo->toDateString()),
        ));

        $direction = $query->direction === 'desc' ? -1 : 1;

        usort($matching, function (Commitment $a, Commitment $b) use ($query, $direction): int {
            $order = match ($query->sort) {
                'amount' => $a->amount <=> $b->amount,
                'created_at' => $a->createdAt <=> $b->createdAt,
                default => $a->dueDate <=> $b->dueDate,
            } * $direction;

            return $order !== 0 ? $order : $a->id <=> $b->id;
        });

        return $this->paginateList($matching, $perPage);
    }

    /**
     * Every stored commitment, seeded or created, by id.
     *
     * @return list<Commitment>
     */
    public function all(): array
    {
        $commitments = $this->commitments;
        ksort($commitments);

        return array_values($commitments);
    }

    /**
     * Reason given to cancel(), if the commitment was cancelled here.
     */
    public function cancellationReason(int $commitmentId): ?string
    {
        return $this->cancellationReasons[$commitmentId] ?? null;
    }

    /**
     * Asserts that create() made at least one commitment, in its current
     * state, that passes the filter.
     *
     * @param  (callable(Commitment): bool)|null  $filter
     */
    public function assertCreated(?callable $filter = null): void
    {
        $created = array_map(fn (int $id): Commitment => $this->commitments[$id], $this->created);
        $matching = $filter === null ? $created : array_filter($created, $filter);

        Assert::assertNotEmpty($matching, 'No matching commitment was created.');
    }

    public function assertNothingCreated(): void
    {
        Assert::assertSame([], $this->created, 'Unexpected commitments were created: '.implode(', ', $this->created).'.');
    }

    /**
     * @return array{name: string, company: CompanyCode, advisorCode: int|null}
     *
     * @throws RecordNotFound
     */
    private function resolveClient(int $clientId): array
    {
        if (isset($this->clients[$clientId])) {
            return $this->clients[$clientId];
        }

        $client = $this->cartera?->client($clientId)?->summary;

        if ($client === null) {
            throw RecordNotFound::for('client', $clientId);
        }

        return ['name' => $client->name, 'company' => $client->company, 'advisorCode' => $client->advisor?->code];
    }

    /**
     * The client exists for the system but the cartera scope hides it.
     */
    private function outOfScope(int $clientId): bool
    {
        return $this->cartera !== null
            && ! isset($this->clients[$clientId])
            && $this->cartera->client($clientId) === null
            && $this->cartera->asSystem()->client($clientId) !== null;
    }

    /**
     * @throws RecordNotFound
     */
    private function findOrFail(int $commitmentId): Commitment
    {
        return $this->find($commitmentId) ?? throw RecordNotFound::for('commitment', $commitmentId);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(): void
    {
        if ($this->denied) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    private function guardAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException("A commitment amount must be greater than zero, {$amount} given.");
        }
    }

    private function guardNotFinal(Commitment $commitment): void
    {
        if (in_array($commitment->status, [CommitmentStatus::Fulfilled, CommitmentStatus::Cancelled], true)) {
            throw new DomainException("Commitment {$commitment->id} is already {$commitment->status->value}.");
        }
    }

    /**
     * Stores a copy of the commitment with some fields changed.
     *
     * @param  array{dueDate?: CarbonImmutable, amount?: float, notes?: string|null, status?: CommitmentStatus, fulfilledAt?: CarbonImmutable}  $changes
     */
    private function replace(Commitment $commitment, array $changes): Commitment
    {
        $replaced = new Commitment(
            id: $commitment->id,
            company: $commitment->company,
            clientId: $commitment->clientId,
            clientName: $commitment->clientName,
            advisorCode: $commitment->advisorCode,
            amount: $changes['amount'] ?? $commitment->amount,
            dueDate: $changes['dueDate'] ?? $commitment->dueDate,
            status: $changes['status'] ?? $commitment->status,
            notes: array_key_exists('notes', $changes) ? $changes['notes'] : $commitment->notes,
            createdBy: $commitment->createdBy,
            createdAt: $commitment->createdAt,
            fulfilledAt: $changes['fulfilledAt'] ?? $commitment->fulfilledAt,
        );

        $this->commitments[$replaced->id] = $replaced;

        return $replaced;
    }
}
