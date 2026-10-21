<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;

/**
 * The legacy rows already imported into "activities", by the string key the
 * migration stores in metadata.legacy_key: "<source>:<table>:<id>", e.g.
 * fase1:actions:17 or supabase:gestiones:42. Read once per run, removed
 * entries included, so neither a second run nor a row repeated in a file
 * imports anything twice.
 */
final class LegacyKeys
{
    /**
     * @param  array<string, true>  $keys
     */
    private function __construct(private array $keys) {}

    public static function ofActivities(): self
    {
        $keys = ActivityRecord::withTrashed()
            ->whereNotNull('metadata->legacy_key')
            ->toBase()
            ->select('metadata->legacy_key as legacy_key')
            ->pluck('legacy_key')
            ->all();

        return new self(array_fill_keys(array_map(strval(...), $keys), true));
    }

    public static function key(LegacySource $source, string $table, string $id): string
    {
        return "{$source->value}:{$table}:{$id}";
    }

    public function has(string $key): bool
    {
        return isset($this->keys[$key]);
    }

    public function add(string $key): void
    {
        $this->keys[$key] = true;
    }
}
