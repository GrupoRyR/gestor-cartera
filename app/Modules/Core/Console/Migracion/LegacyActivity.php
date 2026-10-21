<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Infrastructure\Activity\LastActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A legacy row mapped to one entry of a client's activity log.
 */
final readonly class LegacyActivity
{
    /**
     * Longest summary the log stores (activities.summary).
     */
    public const int SUMMARY_LENGTH = 255;

    /**
     * Longest call outcome the log stores (activities.result).
     */
    public const int RESULT_LENGTH = 150;

    /**
     * @param  string  $key  The legacy key (LegacyKeys::key()).
     * @param  CarbonImmutable  $occurredAt  In the app timezone.
     * @param  array<string, scalar|null>  $metadata  Null values are not stored.
     */
    public function __construct(
        public string $key,
        public TargetClient $client,
        public ActivityType $type,
        public string $summary,
        public ?string $details,
        public ?string $result,
        public LegacyAuthor $author,
        public CarbonImmutable $occurredAt,
        public array $metadata = [],
    ) {}

    /**
     * The first line of $text, shortened with «…» to fit a summary, as the
     * app does for its notes.
     */
    public static function summaryOf(string $text): string
    {
        return Str::limit(trim(Str::before(trim($text), "\n")), self::SUMMARY_LENGTH - 1, '…');
    }

    /**
     * Whether a person wrote it, so it moves the client's last activity, by
     * the app's own rule (LastActivity::counts()): an app user as author and
     * a type other than System. Entries of the legacy system, or of authors
     * the app does not have, do not.
     */
    public function byPerson(): bool
    {
        return LastActivity::counts($this->author->userId, $this->type);
    }
}
