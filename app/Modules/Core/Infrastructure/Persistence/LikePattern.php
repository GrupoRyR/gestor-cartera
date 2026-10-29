<?php

namespace App\Modules\Core\Infrastructure\Persistence;

/**
 * A LIKE search for a fragment typed by a user, where % and _ match
 * themselves (audit S-35). The escape character is named in the SQL because
 * SQLite, used in local tests, has none by default; MySQL 8 runs the same
 * clause in CI and production.
 */
final class LikePattern
{
    /** Escape character of the patterns. */
    public const string ESCAPE = '!';

    /**
     * The pattern that finds $fragment anywhere in the value.
     */
    public static function contains(string $fragment): string
    {
        return '%'.str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $fragment,
        ).'%';
    }

    /**
     * The condition for whereRaw() or orWhereRaw(), with one binding: a
     * pattern of contains(). $expression is SQL written by the caller, never
     * user input.
     */
    public static function sql(string $expression): string
    {
        return "{$expression} LIKE ? ESCAPE '".self::ESCAPE."'";
    }
}
