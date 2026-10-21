<?php

namespace Tests\Modules\Comisiones\Feature\Concerns;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Permissions\ModuleRoles;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with Core or module roles for the Comisiones screen tests.
 * Seed ComisionesPermissionSeeder first so the roles carry the permissions.
 */
final class ComisionesUsers
{
    /**
     * The analyst who calculates: gerente_cartera.
     */
    public static function analyst(string $email = 'ana.gomez@segurex.com', string $name = 'Ana Gómez'): User
    {
        return self::create([Role::PortfolioManager->value], $email, $name);
    }

    /**
     * The approver: the module role comisiones_aprobador.
     */
    public static function approver(string $email = 'beto.ruiz@segurex.com', string $name = 'Beto Ruiz'): User
    {
        return self::create([ModuleRoles::APPROVER], $email, $name);
    }

    /**
     * Read only: the module role comisiones_consulta.
     */
    public static function viewer(string $email = 'carla.diaz@segurex.com', string $name = 'Carla Díaz'): User
    {
        return self::create([ModuleRoles::VIEWER], $email, $name);
    }

    /**
     * A user with these role names (none for an empty list).
     *
     * @param  list<string>  $roles
     */
    public static function create(array $roles, ?string $email = null, string $name = 'María Paula Rojas'): User
    {
        $user = User::factory()->create(array_filter(['name' => $name, 'email' => $email]));

        foreach ($roles as $role) {
            $user->assignRole(SpatieRole::findOrCreate($role, 'web'));
        }

        return $user;
    }

    /**
     * Roles, and no role, without comisiones.view: they never see a settlement.
     *
     * @return array<string, array{list<string>}>
     */
    public static function notViewers(): array
    {
        return [
            'administrador' => [[Role::Admin->value]],
            'asesor' => [[Role::SalesAdvisor->value]],
            'analista de cartera' => [[Role::PortfolioAnalyst->value]],
            'gerente comercial' => [[Role::SalesManager->value]],
            'tesorería' => [[Role::Treasury->value]],
            'sin rol' => [[]],
        ];
    }
}
