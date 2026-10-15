<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * Banks whose statements Bancos reads (Part B, module 5).
 *
 * The value keys the per-bank amounts of DailyCollection and
 * WeeklyCollection.
 */
enum Bank: string
{
    use HasOptions;

    case Bogota = 'bogota';

    case Davivienda = 'davivienda';

    case Bancolombia = 'bancolombia';

    case Miami = 'miami';

    public function label(): string
    {
        return match ($this) {
            self::Bogota => 'Banco de Bogotá',
            self::Davivienda => 'Davivienda',
            self::Bancolombia => 'Bancolombia',
            self::Miami => 'Miami',
        };
    }
}
