<?php

namespace App\Modules\Gerencia\Domain\Mail;

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Gerencia\Domain\Period\ReportPeriod;
use Carbon\CarbonImmutable;

/**
 * Counts the Cobro emails of a period in the activity log.
 *
 * Only entries Cobro marks in their metadata count: an EmailSent with
 * `cobro_run_item_id` is a send, an EmailReceived with `cobro_inbound_id` is
 * a reply, or a bounce when its `bounce` flag is truthy.
 */
final class MailResultsTally
{
    /**
     * @param  iterable<Activity>  $activities  Newest entries of the log, read up to $readLimit.
     * @param  int  $readLimit  The limit the log was read with; reading exactly that many
     *                          entries means older ones were cut off.
     */
    public static function fromActivities(iterable $activities, ReportPeriod $period, int $readLimit): MailResults
    {
        $sent = 0;
        $replies = 0;
        $bounces = 0;
        $read = 0;
        $oldest = null;

        foreach ($activities as $activity) {
            $read++;

            if ($oldest === null || $activity->occurredAt < $oldest) {
                $oldest = $activity->occurredAt;
            }

            if (! $period->contains($activity->occurredAt)) {
                continue;
            }

            if ($activity->type === ActivityType::EmailSent && self::has($activity, 'cobro_run_item_id')) {
                $sent++;
            } elseif ($activity->type === ActivityType::EmailReceived && self::has($activity, 'cobro_inbound_id')) {
                if (filter_var($activity->metadata['bounce'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $bounces++;
                } else {
                    $replies++;
                }
            }
        }

        return new MailResults(
            sent: $sent,
            replies: $replies,
            bounces: $bounces,
            truncated: self::cutOff($read, $readLimit, $oldest, $period),
        );
    }

    private static function has(Activity $activity, string $key): bool
    {
        return ($activity->metadata[$key] ?? null) !== null;
    }

    private static function cutOff(int $read, int $readLimit, ?CarbonImmutable $oldest, ReportPeriod $period): bool
    {
        return $oldest !== null && $read >= $readLimit && $oldest > $period->from;
    }
}
