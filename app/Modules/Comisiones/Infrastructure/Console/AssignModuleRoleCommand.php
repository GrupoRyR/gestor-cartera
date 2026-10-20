<?php

namespace App\Modules\Comisiones\Infrastructure\Console;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * comisiones:rol: assigns (or with --quitar removes) one of the two module
 * roles, comisiones_aprobador or comisiones_consulta, until Core's team
 * screen can. Every change goes to the audit trail.
 */
final class AssignModuleRoleCommand extends Command
{
    protected $signature = 'comisiones:rol
        {email : Correo del usuario}
        {rol : comisiones_aprobador o comisiones_consulta}
        {--quitar : Quita el rol en vez de asignarlo}';

    protected $description = 'Asigna o quita un rol del módulo de comisiones';

    public function handle(AuditTrail $audit, PermissionRegistrar $registrar): int
    {
        $roleName = trim((string) $this->argument('rol'));

        if (! in_array($roleName, ModuleRoles::ALL, true)) {
            $this->components->error('Solo puedes asignar '.implode(' o ', ModuleRoles::ALL).'.');

            return self::FAILURE;
        }

        $email = User::normalizeEmail((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->where('is_active', true)->first();

        if ($user === null) {
            $this->components->error("No hay un usuario activo con el correo {$email}.");

            return self::FAILURE;
        }

        $role = Role::findOrCreate($roleName, 'web');
        $remove = (bool) $this->option('quitar');

        if ($remove) {
            $user->removeRole($role);
        } else {
            $user->assignRole($role);
        }

        $registrar->forgetCachedPermissions();

        $audit->record(
            $remove ? 'comisiones.role.removed' : 'comisiones.role.assigned',
            subjectType: 'user',
            subjectId: $user->id,
            context: ['rol' => $roleName],
        );

        $this->components->info($remove
            ? "Quitaste el rol {$roleName} a {$email}."
            : "Asignaste el rol {$roleName} a {$email}.");

        return self::SUCCESS;
    }
}
