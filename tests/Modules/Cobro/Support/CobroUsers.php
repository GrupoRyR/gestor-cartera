<?php

namespace Tests\Modules\Cobro\Support;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\Role;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Saved users with a Spatie role for the Cobro screen tests. Seed
 * CobroPermissionSeeder first so the roles carry the cobro permissions.
 */
final class CobroUsers
{
    /**
     * An active user with $role, or without any role when null.
     */
    public static function create(?Role $role, string $name = 'María Paula Rojas', ?string $email = null): User
    {
        $user = User::factory()->create(array_filter(['name' => $name, 'email' => $email]));

        if ($role !== null) {
            $user->assignRole(SpatieRole::findOrCreate($role->value, 'web'));
        }

        return $user;
    }

    /**
     * Roles with cobro.manage and cobro.replies.
     *
     * @return array<string, array{Role}>
     */
    public static function managers(): array
    {
        return [
            'gerente de cartera' => [Role::PortfolioManager],
            'analista de cartera' => [Role::PortfolioAnalyst],
        ];
    }

    /**
     * Roles, and no role, without cobro.manage.
     *
     * @return array<string, array{Role|null}>
     */
    public static function notManagers(): array
    {
        return [
            'administrador' => [Role::Admin],
            'asesor' => [Role::SalesAdvisor],
            'gerente comercial' => [Role::SalesManager],
            'tesorería' => [Role::Treasury],
            'sin rol' => [null],
        ];
    }

    /**
     * Roles, and no role, without cobro.view.
     *
     * @return array<string, array{Role|null}>
     */
    public static function notViewers(): array
    {
        return [
            'asesor' => [Role::SalesAdvisor],
            'gerente comercial' => [Role::SalesManager],
            'tesorería' => [Role::Treasury],
            'sin rol' => [null],
        ];
    }
}
