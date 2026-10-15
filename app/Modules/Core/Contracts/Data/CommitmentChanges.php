<?php

namespace App\Modules\Core\Contracts\Data;

use Carbon\CarbonImmutable;

/**
 * Input of CompromisoService::update(). A null field stays unchanged, so
 * notes cannot be cleared through this object.
 */
final readonly class CommitmentChanges
{
    /**
     * @param  CarbonImmutable|null  $dueDate  New promised payment date (date only, America/Bogota).
     * @param  float|null  $amount  New amount in Colombian pesos; must be greater than zero.
     * @param  string|null  $notes  New notes, replacing the old ones.
     */
    public function __construct(
        public ?CarbonImmutable $dueDate = null,
        public ?float $amount = null,
        public ?string $notes = null,
    ) {}
}
