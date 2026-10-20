<?php

namespace App\Modules\Comisiones\Infrastructure\Permissions;

/**
 * The two roles of the Comisiones module, plain Spatie role names next to
 * Core's Role enum. Core's team screen does not assign them yet, so
 * "php artisan comisiones:rol" does (odd/tasks/comisiones-interfaz.md).
 */
final class ModuleRoles
{
    /** Approves settlements and captures (§10); never calculates nor captures. */
    public const string APPROVER = 'comisiones_aprobador';

    /** Read-only access to the settlements. */
    public const string VIEWER = 'comisiones_consulta';

    /** @var list<string> */
    public const array ALL = [self::APPROVER, self::VIEWER];
}
