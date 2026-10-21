<?php

namespace App\Modules\Comisiones\Infrastructure\Controls;

use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\AlertSeverity;

/**
 * The alerts of one kind in a settlement (§9.6), counted.
 */
final readonly class AlertGroup
{
    public function __construct(
        public AlertKind $kind,
        public int $count,
    ) {}

    public function isBlocking(): bool
    {
        return $this->kind->severity() === AlertSeverity::Blocking;
    }
}
