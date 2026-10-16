<?php

namespace App\Modules\Core\Infrastructure\Activity;

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Infrastructure\Access\PortfolioScope;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Portfolio\ClientReach;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * GestionLogger over the "activities" table (ActivityRecord).
 *
 * - log() writes as the signed-in user, for a client within their reach
 *   (ClientReach: every company available to them, not only the active
 *   one), or as the system for any client when no one is signed in. Entries
 *   by a person move the client's last activity forward (LastActivity).
 * - forClient() reads a client within the same reach; recent() reads the
 *   clients of the user's scope in the active company or the group view,
 *   like CarteraRepository. Without a signed-in user both read nothing.
 *
 * Soft-deleted clients and soft-deleted entries never appear, and logging on
 * a soft-deleted client throws RecordNotFound. Lists are newest first: by
 * occurred_at, then by id.
 */
final class DatabaseGestionLogger implements GestionLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly CurrentCompany $currentCompany,
        private readonly ClientReach $reach,
        private readonly ConnectionInterface $database,
    ) {}

    public function log(NewActivity $activity): Activity
    {
        $user = $this->auth->guard()->user();
        $clients = $user === null ? ClientRecord::query() : $this->reach->scope()->clients(ClientRecord::query());
        $client = $clients->whereKey($activity->clientId)->first(['id', 'company', 'name'])
            ?? throw RecordNotFound::for('client', $activity->clientId);

        $author = $user instanceof User ? $user : null;
        // Stored as wall time of the application's time zone, like every other timestamp.
        $occurredAt = ($activity->occurredAt ?? CarbonImmutable::now())->setTimezone(date_default_timezone_get());

        $record = $this->database->transaction(function () use ($activity, $client, $author, $occurredAt): ActivityRecord {
            $record = ActivityRecord::query()->create([
                'company' => $client->company,
                'client_id' => $client->id,
                'type' => $activity->type,
                'summary' => $activity->summary,
                'details' => $activity->details,
                'result' => $activity->result,
                'user_id' => $author?->id,
                'occurred_at' => $occurredAt,
                'metadata' => $activity->metadata === [] ? null : $activity->metadata,
            ]);

            if (LastActivity::counts($author?->id, $activity->type)) {
                LastActivity::advance($client->id, $occurredAt);
            }

            return $record;
        });

        return self::toActivity(
            $record,
            $client->name,
            $author === null ? null : new UserRef($author->id, $author->name, $author->email),
        );
    }

    public function forClient(int $clientId, int $limit = 50): array
    {
        self::guardLimit($limit);

        $client = $this->reach->client($clientId);

        if ($client === null) {
            return [];
        }

        return $this->newest(ActivityRecord::query()->where('client_id', $client->id), $limit);
    }

    public function recent(int $limit = 20): array
    {
        self::guardLimit($limit);

        $scope = PortfolioScope::forUser($this->auth->guard()->user(), $this->currentCompany);

        if ($scope->isEmpty()) {
            return [];
        }

        $entries = ActivityRecord::query()
            ->whereIn('company', array_map(fn (CompanyCode $code): string => $code->value, $scope->companies))
            ->whereIn('client_id', $scope->clients(ClientRecord::query())->select('clients.id'));

        return $this->newest($entries, $limit);
    }

    /**
     * The newest $limit entries of $entries, with their client and author
     * read in one query each: strict models forbid lazy loading.
     *
     * @param  Builder<ActivityRecord>  $entries
     * @return list<Activity>
     */
    private function newest(Builder $entries, int $limit): array
    {
        $records = $entries
            ->with(['client:id,name', 'user:id,name,email'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $activities = [];

        foreach ($records as $record) {
            $author = $record->user;

            $activities[] = self::toActivity(
                $record,
                $record->client->name,
                $author === null ? null : new UserRef($author->id, $author->name, $author->email),
            );
        }

        return $activities;
    }

    private static function toActivity(ActivityRecord $record, string $clientName, ?UserRef $user): Activity
    {
        return new Activity(
            id: $record->id,
            clientId: $record->client_id,
            clientName: $clientName,
            type: $record->type,
            summary: $record->summary,
            details: $record->details,
            result: $record->result,
            user: $user,
            occurredAt: $record->occurred_at,
            metadata: $record->metadata ?? [],
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function guardLimit(int $limit): void
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, {$limit} given.");
        }
    }
}
