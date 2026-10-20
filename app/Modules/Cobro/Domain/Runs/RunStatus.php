<?php

namespace App\Modules\Cobro\Domain\Runs;

/**
 * A run is Sending while any of its clients waits for its send job, then
 * Completed.
 */
enum RunStatus: string
{
    case Sending = 'enviando';
    case Completed = 'terminado';

    public function label(): string
    {
        return match ($this) {
            self::Sending => 'Enviando',
            self::Completed => 'Terminado',
        };
    }
}
