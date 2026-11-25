<?php

namespace App\Modules\Ingesta\Application\Failures;

use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use Carbon\CarbonImmutable;

/**
 * The single entry point for load failure reports: the report endpoint
 * (ReceiveLoadFailureController) now, the Bases SAP reader later.
 *
 * Cleans the message (control and invisible format characters out, runs of
 * whitespace as one space, at most MESSAGE_LENGTH characters) and stores
 * the failure once per report id. Two copies of a report sent at the same
 * time collide on the unique report_id, so only one is stored. The times
 * are stored in the app timezone.
 *
 * It only records: the alert for a final report hooks in here in the next
 * pull request.
 */
final class RecordLoadFailure
{
    /** Longest stored message, in characters. */
    public const int MESSAGE_LENGTH = 300;

    /**
     * Stores the failure unless its report id is already stored.
     *
     * @return bool True when the report is new; false for a repeated report id.
     */
    public function record(LoadFailure $failure): bool
    {
        $timezone = (string) config('app.timezone');

        $record = LoadFailureRecord::query()->createOrFirst(['report_id' => $failure->reportId], [
            'source' => $failure->source,
            'run_id' => $failure->runId,
            'company' => $failure->company,
            'dataset' => $failure->dataset,
            'slot_at' => $failure->slotAt->setTimezone($timezone),
            'stage' => $failure->stage,
            'cause' => $failure->cause,
            'transient' => $failure->transient,
            'attempt' => $failure->attempt,
            'max_attempts' => $failure->maxAttempts,
            'final' => $failure->final,
            'message' => self::clean($failure->message),
            'occurred_at' => $failure->occurredAt->setTimezone($timezone),
            'received_at' => CarbonImmutable::now($timezone),
        ]);

        return $record->wasRecentlyCreated;
    }

    /**
     * The message as stored: valid UTF-8, on one line, without control or
     * invisible format characters, trimmed and cut to MESSAGE_LENGTH
     * characters (never inside a multibyte one).
     */
    public static function clean(string $message): string
    {
        $text = mb_scrub($message, 'UTF-8');
        $text = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $text);
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return rtrim(mb_substr($text, 0, self::MESSAGE_LENGTH, 'UTF-8'));
    }
}
