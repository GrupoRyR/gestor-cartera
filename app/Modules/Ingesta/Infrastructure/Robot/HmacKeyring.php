<?php

namespace App\Modules\Ingesta\Infrastructure\Robot;

use Illuminate\Support\Facades\Log;

/**
 * The robot signing keys, from config('ingesta.hmac_keys'): comma-separated
 * "key-id:secret" pairs (INGESTA_HMAC_KEYS). Several pairs let a new key go
 * live before the old one is removed. A pair is split on its first ":", so
 * a secret may contain colons. Malformed pairs, and pairs whose secret is
 * shorter than MIN_SECRET_BYTES, are skipped with a warning that names
 * their position (and the key id of a short secret), never the secret.
 */
final readonly class HmacKeyring
{
    private const string KEY_ID = '/^[A-Za-z0-9._-]{1,64}$/';

    /** Shortest secret accepted, in bytes (strlen), as for HMAC-SHA256 keys. */
    public const int MIN_SECRET_BYTES = 32;

    /**
     * @param  array<string, string>  $secrets  Secret by key id.
     */
    private function __construct(
        private array $secrets,
    ) {}

    public static function parse(string $pairs): self
    {
        $secrets = [];

        foreach (explode(',', $pairs) as $position => $pair) {
            if (trim($pair) === '') {
                continue;
            }

            [$id, $secret] = array_map(trim(...), explode(':', $pair, 2) + [1 => '']);

            if (preg_match(self::KEY_ID, $id) !== 1 || $secret === '') {
                Log::warning('Ingesta: skipped a malformed pair in INGESTA_HMAC_KEYS; expected "key-id:secret".', [
                    'position' => $position + 1,
                ]);

                continue;
            }

            if (strlen($secret) < self::MIN_SECRET_BYTES) {
                Log::warning('Ingesta: skipped a key in INGESTA_HMAC_KEYS whose secret is shorter than '.self::MIN_SECRET_BYTES.' bytes.', [
                    'position' => $position + 1,
                    'key' => $id,
                ]);

                continue;
            }

            $secrets[$id] = $secret;
        }

        return new self($secrets);
    }

    /**
     * Whether the key id looks like one this keyring could hold, so it is
     * safe to write to the logs.
     */
    public static function isWellFormedId(string $id): bool
    {
        return preg_match(self::KEY_ID, $id) === 1;
    }

    public function secret(string $id): ?string
    {
        return $this->secrets[$id] ?? null;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        // A numeric id ("2026") becomes an integer array key.
        return array_map(strval(...), array_keys($this->secrets));
    }

    public function isEmpty(): bool
    {
        return $this->secrets === [];
    }
}
