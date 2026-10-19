<?php

namespace Tests\Modules\Ingesta\Support;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role for the Ingesta screen tests. Seed
 * IngestaPermissionSeeder first so the roles carry the ingesta permissions.
 */
final class IngestaUsers
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
     * Roles that may open the load history and the manual upload.
     *
     * @return array<string, array{Role}>
     */
    public static function allowed(): array
    {
        return [
            'administrador' => [Role::Admin],
            'gerente de cartera' => [Role::PortfolioManager],
            'analista de cartera' => [Role::PortfolioAnalyst],
        ];
    }

    /**
     * Roles, and no role, that get 403.
     *
     * @return array<string, array{Role|null}>
     */
    public static function forbidden(): array
    {
        return [
            'asesor' => [Role::SalesAdvisor],
            'gerente comercial' => [Role::SalesManager],
            'tesorería' => [Role::Treasury],
            'sin rol' => [null],
        ];
    }
}
