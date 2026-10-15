<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Assert;
use Tests\Fakes\Concerns\QueriesInMemory;

/**
 * In-memory GestionLogger.
 *
 * Client names come from knowClient() or, when given, from the
 * CarteraRepository fake, whose scope it honours: logging for a client it
 * cannot see throws RecordNotFound, and reads leave out clients that exist
 * but are out of scope. Without a cartera fake, unknown clients get a
 * placeholder name. Seed earlier entries with add(); only log() counts for
 * the assertions.
 */
final class FakeGestionLogger implements GestionLogger
{
    use QueriesInMemory;

    /**
     * @var list<Activity>
     */
    private array $activities = [];

    /**
     * Activities written through log(), in order.
     *
     * @var list<Activity>
     */
    private array $logged = [];

    /**
     * @var array<int, string>
     */
    private array $clients = [];

    private int $nextId = 1;

    private ?UserRef $user;

    public function __construct(
        private readonly ?CarteraRepository $cartera = null,
    ) {
        $this->user = new UserRef(1, 'Usuario de prueba', 'prueba@example.com');
    }

    /**
     * The author of the next entries; null logs as the system.
     */
    public function actingAs(?UserRef $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function knowClient(int $id, string $name): static
    {
        $this->clients[$id] = $name;

        return $this;
    }

    /**
     * Seeds earlier entries without counting them as logged.
     */
    public function add(Activity ...$activities): static
    {
        foreach ($activities as $activity) {
            $this->activities[] = $activity;
            $this->nextId = max($this->nextId, $activity->id + 1);
        }

        return $this;
    }

    public function log(NewActivity $activity): Activity
    {
        $logged = new Activity(
            id: $this->nextId++,
            clientId: $activity->clientId,
            clientName: $this->clientName($activity->clientId),
            type: $activity->type,
            summary: $activity->summary,
            details: $activity->details,
            result: $activity->result,
            user: $this->user,
            occurredAt: $activity->occurredAt ?? CarbonImmutable::now(),
            metadata: $activity->metadata,
        );

        $this->activities[] = $logged;
        $this->logged[] = $logged;

        return $logged;
    }

    public function forClient(int $clientId, int $limit = 50): array
    {
        $this->guardAtLeastOne($limit, 'limit');

        if ($this->outOfScope($clientId)) {
            return [];
        }

        return $this->newest(
            array_filter($this->activities, fn (Activity $activity): bool => $activity->clientId === $clientId),
            $limit,
        );
    }

    public function recent(int $limit = 20): array
    {
        $this->guardAtLeastOne($limit, 'limit');

        return $this->newest(
            array_filter($this->activities, fn (Activity $activity): bool => ! $this->outOfScope($activity->clientId)),
            $limit,
        );
    }

    /**
     * Activities written through log(), in order.
     *
     * @return list<Activity>
     */
    public function logged(): array
    {
        return $this->logged;
    }

    public function assertLogged(ActivityType $type, ?int $clientId = null): void
    {
        $matching = array_filter(
            $this->logged,
            fn (Activity $activity): bool => $activity->type === $type && ($clientId === null || $activity->clientId === $clientId),
        );

        Assert::assertNotEmpty(
            $matching,
            "No {$type->value} activity was logged".($clientId === null ? '.' : " for client {$clientId}."),
        );
    }

    public function assertNothingLogged(): void
    {
        Assert::assertSame([], $this->logged, count($this->logged).' activities were logged unexpectedly.');
    }

    /**
     * @throws RecordNotFound
     */
    private function clientName(int $clientId): string
    {
        if (isset($this->clients[$clientId])) {
            return $this->clients[$clientId];
        }

        if ($this->cartera === null) {
            return "Cliente {$clientId}";
        }

        return $this->cartera->client($clientId)?->summary->name ?? throw RecordNotFound::for('client', $clientId);
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
     * @param  array<int, Activity>  $activities
     * @return list<Activity>
     */
    private function newest(array $activities, int $limit): array
    {
        usort($activities, fn (Activity $a, Activity $b): int => [$b->occurredAt, $b->id] <=> [$a->occurredAt, $a->id]);

        return array_slice($activities, 0, $limit);
    }
}
