<?php

namespace App\Modules\Core\Contracts\Data;

use Carbon\CarbonImmutable;

/**
 * Input of CompromisoService::create().
 */
final readonly class NewCommitment
{
    /**
     * @param  int  $clientId  Platform id of the client (ClientSummary::$id).
     * @param  CarbonImmutable  $dueDate  Promised payment date (date only, America/Bogota).
     * @param  float  $amount  Promised amount in Colombian pesos; must be greater than zero.
     * @param  string|null  $notes  Free text shown with the commitment.
     */
    public function __construct(
        public int $clientId,
        public CarbonImmutable $dueDate,
        public float $amount,
        public ?string $notes = null,
    ) {}
}
