<?php

namespace Tests\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Design\Support\Html;

/**
 * Users of the Equipo screen tests and readers of what the screen saved,
 * always read again from the database.
 */
final class TeamUsers
{
    /**
     * The admin who uses the screen.
     */
    public static function admin(string $name = 'Laura Admin', string $email = 'laura.admin@segurex.com'): User
    {
        return self::member($name, $email, Role::Admin);
    }

    /**
     * A saved user with a fixed name and e-mail, $role (none when null) and
     * $companies; deactivated or soft-deleted on request.
     *
     * @param  list<CompanyCode>  $companies
     */
    public static function member(
        string $name,
        string $email,
        ?Role $role,
        array $companies = [],
        bool $active = true,
        bool $deleted = false,
    ): User {
        $user = CoreUsers::create($role, ...$companies);
        $user->update(['name' => $name, 'email' => $email, 'is_active' => $active]);

        if ($deleted) {
            $user->delete();
        }

        return $user;
    }

    /**
     * The role of the user, or null without one.
     */
    public static function roleOf(User $user): ?string
    {
        return User::withTrashed()->findOrFail($user->getKey())->getRoleNames()->first();
    }

    /**
     * The codes of the companies the user is assigned to, in code order.
     *
     * @return list<string>
     */
    public static function companiesOf(User $user): array
    {
        return CompanyRecord::assignedTo($user)->orderBy('companies.code')->pluck('companies.code')
            ->map(fn (CompanyCode $code): string => $code->value)
            ->values()
            ->all();
    }

    /**
     * The audit entries of $action, oldest first.
     *
     * @return list<AuditLogRecord>
     */
    public static function audits(string $action): array
    {
        return AuditLogRecord::query()->where('action', $action)->orderBy('id')->get()->all();
    }

    public static function html(TestResponse $response): Html
    {
        return Html::parse($response->getContent() ?: '');
    }

    /**
     * The names in the first column of the users table, in page order.
     *
     * @return list<string>
     */
    public static function listedNames(TestResponse $response): array
    {
        return array_map(
            fn ($cell): string => Html::clean($cell->textContent),
            self::html($response)->all('//table/tbody/tr[not(@data-part="empty")]/td[1]'),
        );
    }

    /**
     * Each tab label with its count.
     *
     * @return array<string, int>
     */
    public static function tabCounts(TestResponse $response): array
    {
        $counts = [];

        foreach (self::html($response)->all('//nav[@aria-label="Estado de los usuarios"]//a') as $link) {
            $spans = $link->getElementsByTagName('span');
            $counts[Html::clean($spans->item(0)?->textContent ?? '')] = (int) Html::clean($spans->item(1)?->textContent ?? '');
        }

        return $counts;
    }
}
