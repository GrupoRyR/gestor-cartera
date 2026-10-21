<?php

namespace App\Modules\Core\Console\Migracion;

/**
 * Who wrote a legacy row: a user of this app, the legacy system itself (no
 * author), or a person the app does not have, kept by their legacy name.
 */
final readonly class LegacyAuthor
{
    /**
     * @param  int|null  $userId  The app user; null for the system or an unknown person.
     * @param  string|null  $legacy  How the legacy system named an author the app does not have (an e-mail, or «usuario Fase 1 #7»).
     */
    private function __construct(
        public ?int $userId,
        public ?string $legacy,
    ) {}

    public static function user(int $userId): self
    {
        return new self($userId, null);
    }

    public static function system(): self
    {
        return new self(null, null);
    }

    public static function unknown(string $legacy): self
    {
        return new self(null, $legacy);
    }

    /**
     * A person wrote it, but the app does not have them.
     */
    public function isUnknown(): bool
    {
        return $this->legacy !== null;
    }
}
