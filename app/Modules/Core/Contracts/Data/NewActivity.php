<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\ActivityType;
use Carbon\CarbonImmutable;

/**
 * Input of GestionLogger::log(): one entry of a client's activity log.
 */
final readonly class NewActivity
{
    /**
     * @param  int  $clientId  Platform id of the client.
     * @param  string  $summary  One line shown in the log, e.g. "Llamada a tesorería".
     * @param  string|null  $details  Longer free text.
     * @param  string|null  $result  Outcome of a call, e.g. "No contesta"; null for other types.
     * @param  CarbonImmutable|null  $occurredAt  When it happened; null = now.
     * @param  array<string, scalar|null>  $metadata  Extra machine-readable data, e.g.
     *                                                ['commitment_id' => 12] or ['internet_message_id' => '<…>'].
     */
    public function __construct(
        public int $clientId,
        public ActivityType $type,
        public string $summary,
        public ?string $details = null,
        public ?string $result = null,
        public ?CarbonImmutable $occurredAt = null,
        public array $metadata = [],
    ) {}
}
