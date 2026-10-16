<?php

namespace App\Modules\Comisiones\Domain\Calculation;

use App\Modules\Comisiones\Domain\Shared\Money;

/**
 * One of the roles a person is paid for, with its amount (§5.7).
 */
final readonly class PersonRole
{
    /**
     * @param  string  $reference  The code ("14"), the derived key ("81.1") or the team key.
     * @param  string  $label  As the consolidated report shows it ("Código 14 – Mónica Rivera").
     */
    public function __construct(
        public RoleKind $kind,
        public string $reference,
        public string $label,
        public Money $amount,
    ) {}
}
