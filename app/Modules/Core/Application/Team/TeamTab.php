<?php

namespace App\Modules\Core\Application\Team;

/**
 * The tabs of the Equipo list. The value is the "estado" query parameter.
 */
enum TeamTab: string
{
    /** Active users with a role: the people who use the platform. */
    case Active = 'activos';

    /** Active users without a role: they signed in and wait for access. */
    case WithoutRole = 'sin-rol';

    /** Deactivated users, with or without a role. */
    case Inactive = 'inactivos';

    /** Soft-deleted users, who can be restored. */
    case Deleted = 'eliminados';

    /**
     * The tab label, in Spanish.
     */
    public function label(): string
    {
        return __("core::equipo.pestanas.{$this->value}");
    }
}
