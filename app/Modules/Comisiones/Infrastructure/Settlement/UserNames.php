<?php

namespace App\Modules\Comisiones\Infrastructure\Settlement;

use App\Models\User;

/**
 * The names of the users a settlement names by e-mail (calculated, approved
 * and closed by). The engine compares users as e-mails; the screens show
 * names. A user who left keeps their name (soft-deleted rows count); an
 * e-mail without a user is shown as is.
 */
final class UserNames
{
    /**
     * @param  iterable<string|null>  $emails
     * @return array<string, string> Lowercased e-mail => name.
     */
    public function of(iterable $emails): array
    {
        $wanted = [];

        foreach ($emails as $email) {
            if ($email !== null && trim($email) !== '') {
                $wanted[mb_strtolower(trim($email))] = true;
            }
        }

        if ($wanted === []) {
            return [];
        }

        $names = [];

        foreach (User::withTrashed()->whereIn('email', array_keys($wanted))->get(['email', 'name']) as $user) {
            $names[mb_strtolower($user->email)] = $user->name;
        }

        foreach (array_keys($wanted) as $email) {
            $names[$email] ??= $email;
        }

        return $names;
    }

    /**
     * The name of one e-mail in a map built by of(), or null for no user.
     *
     * @param  array<string, string>  $names
     */
    public static function name(array $names, ?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return null;
        }

        return $names[mb_strtolower(trim($email))] ?? $email;
    }
}
