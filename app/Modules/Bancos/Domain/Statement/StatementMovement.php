<?php

namespace App\Modules\Bancos\Domain\Statement;

use App\Modules\Bancos\Domain\Movement\MovementType;
use Carbon\CarbonImmutable;

/**
 * One movement in the normalized format shared by the file readers and the
 * Treasury API: whatever the bank prints, a movement is a date, a direction,
 * a positive amount and some free text.
 */
final readonly class StatementMovement
{
    /**
     * @param  CarbonImmutable  $date  Movement date (date only, America/Bogota).
     * @param  string  $amount  Positive decimal with two places and a dot: "1234567.89".
     * @param  string  $currency  ISO 4217 code, e.g. "COP" or "USD".
     */
    public function __construct(
        public CarbonImmutable $date,
        public MovementType $type,
        public string $amount,
        public ?string $description = null,
        public ?string $reference = null,
        public ?string $account = null,
        public string $currency = 'COP',
    ) {}

    public function isCredit(): bool
    {
        return $this->type === MovementType::Credit;
    }
}
