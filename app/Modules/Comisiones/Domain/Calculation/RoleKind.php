<?php

namespace App\Modules\Comisiones\Domain\Calculation;

/**
 * The kind of role a person is paid for (§5.7).
 */
enum RoleKind: string
{
    /** A seller code of the person ("Código 14 – Mónica Rivera"). */
    case Code = 'codigo';

    /** A derived commission (81.1, 85.1). */
    case Derived = 'especial';

    /** A channel director team, when placed apart (§14.4). */
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Code => 'Código',
            self::Derived => 'Especial',
            self::Director => 'Director',
        };
    }
}
