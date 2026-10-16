<?php

namespace App\Modules\Ingesta\Application;

/**
 * Error messages keyed like Laravel validation errors ("rows.3.saldo"),
 * with a cap on the number of keys: a broken extraction would otherwise
 * answer with one error per row.
 */
final class ValidationErrors
{
    /** Most error keys collected for one batch. */
    public const int MAX_KEYS = 50;

    /**
     * @var array<string, list<string>>
     */
    private array $messages = [];

    /**
     * Adds a message; ignored once the cap is reached, unless the key
     * already has messages.
     */
    public function add(string $key, string $message): void
    {
        if (! isset($this->messages[$key]) && $this->full()) {
            return;
        }

        $this->messages[$key][] = $message;
    }

    /**
     * Whether no new key fits; callers stop looking for more errors.
     */
    public function full(): bool
    {
        return count($this->messages) >= self::MAX_KEYS;
    }

    /**
     * @return array<string, list<string>>
     */
    public function all(): array
    {
        return $this->messages;
    }
}
