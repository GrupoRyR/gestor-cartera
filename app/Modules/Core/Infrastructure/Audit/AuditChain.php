<?php

namespace App\Modules\Core\Infrastructure\Audit;

use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Encryption\MissingAppKeyException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The hash chain of "audit_logs" (audit S-14): tamper evidence, not
 * prevention.
 *
 * Every row stores in "chain_hash" an HMAC-SHA256 of the previous row's hash
 * and of its own contents (every column but id and chain_hash), keyed with a
 * key derived from APP_KEY, which never lives in the database. The one row
 * of "audit_chain_head" keeps the id and hash of the last link. Someone with
 * only database access can change, delete or insert rows through the query
 * builder or phpMyAdmin, but cannot compute the hashes that would hide it,
 * and core:verificar-auditoria then fails.
 *
 * The hash does not include the id, unknown before the insert; the order is
 * proven by the chain of previous hashes. It covers decoded values, never
 * the stored text: MySQL's JSON type reorders object keys and normalizes
 * spacing, so changes and context are hashed as recursively key-sorted JSON.
 */
final class AuditChain
{
    /**
     * The only row of audit_chain_head.
     */
    public const int HEAD_ID = 1;

    /**
     * Separates this key from any other use of APP_KEY.
     */
    private const string PURPOSE = 'core.audit_chain';

    private const string HEAD = 'audit_chain_head';

    private const int CHUNK = 500;

    /**
     * Every hashed column, in payload order.
     */
    private const array COLUMNS = ['action', 'subject_type', 'subject_id', 'changes', 'context', 'user_id', 'actor', 'ip_address', 'user_agent', 'created_at'];

    /**
     * Inserts an audit row chained after the head and moves the head to it.
     *
     * Runs in a transaction that locks the head row, so appends are
     * serialized and ids follow the chain. Inside a caller's transaction it
     * is a savepoint: the lock is held until the caller commits, and a
     * rollback also undoes the head.
     *
     * @param  array<string, mixed>  $attributes  The row as AuditLogRecord takes it, created_at included.
     */
    public function append(array $attributes): void
    {
        DB::transaction(function () use ($attributes): void {
            $head = $this->lockHead();
            $hash = $this->hash($head['last_hash'], $attributes);

            $record = AuditLogRecord::query()->create([...$attributes, 'chain_hash' => $hash]);

            $this->moveHead($record->id, $hash);
        });
    }

    /**
     * Rehashes every row from the first, in id order, and moves the head to
     * the last. Only for the migration that introduced the chain, whose rows
     * had no hash; the number of rows sealed.
     */
    public function seal(): int
    {
        return DB::transaction(function (): int {
            $this->lockHead();

            $previous = null;
            $lastId = null;
            $sealed = 0;

            foreach (DB::table('audit_logs')->lazyById(self::CHUNK) as $row) {
                $row = (array) $row;
                $previous = $this->hash($previous, $row);
                $lastId = (int) $row['id'];

                // chain_hash only: the query builder is the one way to fill it on an existing row.
                DB::table('audit_logs')->where('id', $lastId)->update(['chain_hash' => $previous]);
                $sealed++;
            }

            $this->moveHead($lastId, $previous);

            return $sealed;
        });
    }

    /**
     * The last link as stored, read without a lock; null when the head row
     * itself is missing.
     *
     * @return array{last_id: int|null, last_hash: string|null}|null
     */
    public function head(): ?array
    {
        $head = DB::table(self::HEAD)->where('id', self::HEAD_ID)->first();

        return $head === null ? null : self::headValues($head);
    }

    /**
     * The hash of $row after $previous (null for the first row), with the current key.
     *
     * @param  array<string, mixed>  $row  A row of audit_logs as the query builder reads it, or as AuditLogRecord takes it.
     *
     * @throws MissingAppKeyException when APP_KEY is not set.
     */
    public function hash(?string $previous, array $row): string
    {
        return self::sign($this->payload($previous, $row), self::keys()[0]);
    }

    /**
     * Whether $hash is the hash of $row after $previous with the current key
     * or one of APP_PREVIOUS_KEYS.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws MissingAppKeyException when APP_KEY is not set.
     */
    public function matches(?string $previous, array $row, string $hash): bool
    {
        $payload = $this->payload($previous, $row);

        foreach (self::keys() as $key) {
            if (hash_equals(self::sign($payload, $key), $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{last_id: int|null, last_hash: string|null}
     */
    private function lockHead(): array
    {
        $head = DB::table(self::HEAD)->where('id', self::HEAD_ID)->lockForUpdate()->first();

        if ($head === null) {
            throw new LogicException('The audit chain head is missing: run the migrations.');
        }

        return self::headValues($head);
    }

    private function moveHead(?int $lastId, ?string $lastHash): void
    {
        DB::table(self::HEAD)->where('id', self::HEAD_ID)->update([
            'last_id' => $lastId,
            'last_hash' => $lastHash,
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The previous hash and the canonical row, as one string.
     *
     * @param  array<string, mixed>  $row
     */
    private function payload(?string $previous, array $row): string
    {
        $values = [];

        foreach (self::COLUMNS as $column) {
            $value = $row[$column] ?? null;

            $values[$column] = match ($column) {
                'changes', 'context' => $value === null ? null : self::canonicalJson($value),
                'user_id' => $value === null ? null : (int) $value,
                'created_at' => self::canonicalTime($value),
                default => $value === null ? null : (string) $value,
            };
        }

        // Shortest round-trip floats whatever php.ini says, so the payload of
        // a row is the same string on every run.
        $precision = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');

        try {
            $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } finally {
            ini_set('serialize_precision', $precision === false ? '-1' : $precision);
        }

        return ($previous ?? '')."\n".$json;
    }

    /**
     * Decoded JSON with object keys sorted at every depth. A stored value is
     * text; a value about to be stored goes through the same encoding first,
     * so enums, dates and other JSON-serializable objects end up equal.
     */
    private static function canonicalJson(mixed $value): mixed
    {
        $json = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR);

        return self::sortKeys(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (is_array($value)) {
            ksort($value, SORT_STRING);

            return array_map(self::sortKeys(...), $value);
        }

        // MySQL may print 1500.0 as 1500: whole floats count as integers.
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) <= 2 ** 53) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * created_at in UTC, to the second. A stored value is the text Eloquent
     * wrote in the app's time zone ("Y-m-d H:i:s").
     */
    private static function canonicalTime(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $value instanceof CarbonInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
        $timezone = config('app.timezone');

        return CarbonImmutable::parse($text, is_string($timezone) ? $timezone : 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{last_id: int|null, last_hash: string|null}
     */
    private static function headValues(object $head): array
    {
        $values = (array) $head;

        return [
            'last_id' => $values['last_id'] === null ? null : (int) $values['last_id'],
            'last_hash' => $values['last_hash'] === null ? null : (string) $values['last_hash'],
        ];
    }

    private static function sign(string $payload, string $appKey): string
    {
        return hash_hmac('sha256', $payload, hash_hmac('sha256', self::PURPOSE, $appKey, true));
    }

    /**
     * The raw app key, then the raw previous keys, decoded like the
     * encrypter does ("base64:…").
     *
     * @return non-empty-list<string>
     *
     * @throws MissingAppKeyException when APP_KEY is not set.
     */
    private static function keys(): array
    {
        $current = config('app.key');

        if (! is_string($current) || $current === '') {
            throw new MissingAppKeyException;
        }

        $previous = config('app.previous_keys', []);
        $keys = [$current, ...(is_array($previous) ? array_filter($previous, fn (mixed $key): bool => is_string($key) && $key !== '') : [])];

        return array_values(array_map(
            fn (string $key): string => str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key,
            $keys,
        ));
    }
}
