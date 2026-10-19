<?php

namespace App\Modules\Comisiones\Domain\Calculation;

/**
 * Whether an alert stops the approval until someone justifies it (§9.7).
 */
enum AlertSeverity: string
{
    case Blocking = 'bloqueante';
    case Warning = 'advertencia';

    public function label(): string
    {
        return match ($this) {
            self::Blocking => 'Bloqueante',
            self::Warning => 'Advertencia',
        };
    }
}
