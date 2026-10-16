<?php

namespace Tests\Modules\Core\Support;

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Entries of the clients' log (bitácora de gestiones) for the GestionLogger
 * tests, and readers of what the logger returns.
 */
final class ActivityRecords
{
    /**
     * The logger of the container, as a controller gets it.
     */
    public static function logger(): GestionLogger
    {
        return app(GestionLogger::class);
    }

    /**
     * A saved note of $client summarised as $summary, written by $author (the
     * system when null) at $occurredAt (America/Bogota).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function entry(
        ClientRecord $client,
        string $summary,
        string $occurredAt,
        ?Authenticatable $author = null,
        array $attributes = [],
    ): ActivityRecord {
        return ActivityRecord::factory()->create([
            'client_id' => $client->id,
            'company' => $client->company,
            'type' => ActivityType::Note,
            'summary' => $summary,
            'user_id' => $author?->getAuthIdentifier(),
            'occurred_at' => CarteraRecords::at($occurredAt),
            ...$attributes,
        ]);
    }

    /**
     * The summaries of $activities, in order.
     *
     * @param  list<Activity>  $activities
     * @return list<string>
     */
    public static function summaries(array $activities): array
    {
        return array_map(fn (Activity $activity): string => $activity->summary, $activities);
    }
}
