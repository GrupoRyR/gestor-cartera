<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Infrastructure\Activity\LastActivity;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use Carbon\CarbonImmutable;

/**
 * Writes legacy entries into the clients' activity log ("activities"),
 * each with its legacy key in metadata.legacy_key. Entries by a person
 * (LegacyActivity::byPerson()) move the client's last activity forward,
 * once per client at the end (advanceLastActivity()), and only when later:
 * an old entry never moves it back. Nothing else changes: no status, no
 * notifications.
 */
final class LegacyActivities
{
    /**
     * @var array<int, CarbonImmutable> Client id => newest entry by a person written in this run.
     */
    private array $newest = [];

    public function __construct(private readonly LegacyKeys $keys) {}

    /**
     * Whether the legacy row with $key is already in the log.
     */
    public function has(string $key): bool
    {
        return $this->keys->has($key);
    }

    public function save(LegacyActivity $activity): void
    {
        ActivityRecord::query()->create([
            'company' => $activity->client->company,
            'client_id' => $activity->client->id,
            'type' => $activity->type,
            'summary' => $activity->summary,
            'details' => $activity->details,
            'result' => $activity->result,
            'user_id' => $activity->author->userId,
            'occurred_at' => $activity->occurredAt,
            'metadata' => array_filter(
                ['legacy_key' => $activity->key, ...$activity->metadata],
                fn (mixed $value): bool => $value !== null,
            ),
        ]);

        $this->keys->add($activity->key);

        $newest = $this->newest[$activity->client->id] ?? null;

        if ($activity->byPerson() && ($newest === null || $activity->occurredAt->greaterThan($newest))) {
            $this->newest[$activity->client->id] = $activity->occurredAt;
        }
    }

    /**
     * Moves the last activity of each client written to, when its newest
     * entry by a person is later than what the client has.
     */
    public function advanceLastActivity(): void
    {
        foreach ($this->newest as $clientId => $at) {
            LastActivity::advance($clientId, $at);
        }
    }
}
