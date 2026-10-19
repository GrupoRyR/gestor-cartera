<?php

namespace Tests\Modules\Bancos\Support;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role for the Bancos screen tests. Seed
 * BancosPermissionSeeder first so the roles carry the bancos permissions.
 */
final class BancosUsers
{
    /**
     * An active user with $role, or without any role when null.
     */
    public static function create(?Role $role, string $name = 'María Paula Rojas'): User
    {
        $user = User::factory()->create(['name' => $name]);

        if ($role !== null) {
            $user->assignRole(SpatieRole::findOrCreate($role->value, 'web'));
        }

        return $user;
    }

    /**
     * Roles that see the movements, the report and the loads.
     *
     * @return array<string, array{Role}>
     */
    public static function viewers(): array
    {
        return [
            'gerente de cartera' => [Role::PortfolioManager],
            'analista de cartera' => [Role::PortfolioAnalyst],
            'tesorería' => [Role::Treasury],
        ];
    }

    /**
     * Roles, and no role, that get 403.
     *
     * @return array<string, array{Role|null}>
     */
    public static function outsiders(): array
    {
        return [
            'administrador' => [Role::Admin],
            'asesor' => [Role::SalesAdvisor],
            'gerente comercial' => [Role::SalesManager],
            'sin rol' => [null],
        ];
    }
}
