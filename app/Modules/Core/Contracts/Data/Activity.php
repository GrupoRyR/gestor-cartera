<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\ActivityType;
use Carbon\CarbonImmutable;

/**
 * A stored entry of a client's activity log (gestión / bitácora).
 */
final readonly class Activity
{
    /**
     * @param  string  $clientName  Client name at the time of reading, for lists.
     * @param  string|null  $result  Outcome of a call; null for other types.
     * @param  UserRef|null  $user  Author; null when the platform wrote it (jobs, imports).
     * @param  CarbonImmutable  $occurredAt  When it happened.
     * @param  array<string, scalar|null>  $metadata  Extra machine-readable data (see NewActivity).
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $clientName,
        public ActivityType $type,
        public string $summary,
        public ?string $details,
        public ?string $result,
        public ?UserRef $user,
        public CarbonImmutable $occurredAt,
        public array $metadata = [],
    ) {}
}
