<?php

namespace App\Modules\Core\Console;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\Role;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * core:superadmin: the only way to make a person superadmin, or with
 * --quitar to take the role away. The team screen never offers the role
 * nor changes a superadmin (TeamMembers).
 *
 * The person must have signed in with Microsoft at least once
 * (users.microsoft_id), be active and not deleted. Granting replaces their
 * role, since a person has one role; removing leaves them without a role,
 * so an admin then gives them one from the team screen. Each change goes to
 * the audit trail with the context source "consola".
 */
final class SuperAdminCommand extends Command
{
    private const array CONTEXT = ['source' => 'consola'];

    protected $signature = 'core:superadmin
        {email : Correo de la persona}
        {--quitar : Quita el rol de superadministrador en vez de asignarlo}';

    protected $description = 'Asigna o quita el rol de superadministrador';

    public function handle(AuditLogger $audit, ConnectionInterface $database): int
    {
        $email = User::normalizeEmail((string) $this->argument('email'));
        $user = User::query()->where('email', $email)->whereNotNull('microsoft_id')->first();

        if ($user === null) {
            return $this->refuse(__('core::superadmin.sin_ingreso', ['correo' => $email]));
        }

        if (! $user->is_active) {
            return $this->refuse(__('core::superadmin.inactiva', ['correo' => $email]));
        }

        $isSuperAdmin = $user->hasRole(Role::SuperAdmin->value);

        if ($this->option('quitar')) {
            if (! $isSuperAdmin) {
                return $this->refuse(__('core::superadmin.no_es', ['correo' => $email]));
            }

            $database->transaction(function () use ($user, $audit): void {
                $user->syncRoles([]);
                $audit->record('core.user.superadmin_revoked', 'user', $user->id, [
                    'role' => ['old' => Role::SuperAdmin->value, 'new' => null],
                ], self::CONTEXT);
            });

            $this->components->info(__('core::superadmin.quitado', ['correo' => $email]));

            return self::SUCCESS;
        }

        if ($isSuperAdmin) {
            $this->components->info(__('core::superadmin.ya_es', ['correo' => $email]));

            return self::SUCCESS;
        }

        $database->transaction(function () use ($user, $audit): void {
            $old = $user->getRoleNames()->first();

            $user->syncRoles([SpatieRole::findOrCreate(Role::SuperAdmin->value, 'web')]);
            $audit->record('core.user.superadmin_granted', 'user', $user->id, [
                'role' => ['old' => $old, 'new' => Role::SuperAdmin->value],
            ], self::CONTEXT);
        });

        $this->components->info(__('core::superadmin.asignado', ['correo' => $email]));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }
}
