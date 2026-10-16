<?php

namespace App\Modules\Comisiones\Domain\Settlement;

use DomainException;

/**
 * A step of the monthly flow asked from the wrong status (§6): only a draft
 * is approved, only an approved settlement is closed and only a closed one
 * is complemented.
 */
final class InvalidTransition extends DomainException
{
    /**
     * @param  string  $action  The verb that was refused: "approve", "close", "complement".
     */
    public static function cannot(string $action, Settlement $settlement): self
    {
        return new self(sprintf(
            'Cannot %s version %d of the %s settlement while it is %s.',
            $action,
            $settlement->version,
            $settlement->period->key(),
            strtolower($settlement->status->name),
        ));
    }
}
