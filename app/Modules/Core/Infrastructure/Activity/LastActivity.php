<?php

namespace App\Modules\Core\Infrastructure\Activity;

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * clients.last_activity_at, cached for ClientSummary::$lastActivityAt and the
 * "inactive since" filter: when someone last worked on the client, that is,
 * its latest log entry written by a person. Entries of the platform (no
 * author, or of type System) do not count, so automatic e-mails and status
 * changes never hide a client nobody has worked on.
 */
final class LastActivity
{
    /**
     * Whether an entry by $userId (null = the platform) of $type counts.
     */
    public static function counts(?int $userId, ActivityType $type): bool
    {
        return $userId !== null && $type !== ActivityType::System;
    }

    /**
     * Moves the client's last activity to $at when it is later, in one
     * statement, so a backdated entry never moves it back.
     */
    public static function advance(int $clientId, CarbonImmutable $at): void
    {
        ClientRecord::withTrashed()
            ->whereKey($clientId)
            ->where(fn (Builder $older) => $older->whereNull('last_activity_at')->orWhere('last_activity_at', '<', $at))
            ->update(['last_activity_at' => $at]);
    }

    /**
     * Sets the client's last activity to its latest entry that counts and is
     * not removed, or to null without one; after an entry is removed.
     */
    public static function recompute(int $clientId): void
    {
        $latest = ActivityRecord::query()
            ->where('client_id', $clientId)
            ->whereNotNull('user_id')
            ->where('type', '!=', ActivityType::System->value)
            ->max('occurred_at');

        ClientRecord::withTrashed()->whereKey($clientId)->update(['last_activity_at' => $latest]);
    }
}
