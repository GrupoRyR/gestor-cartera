<?php

namespace App\Modules\Core\Console\Migracion;

use App\Models\User;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * One data row of a legacy CSV file, with typed getters. A getter that
 * cannot read its column throws RowRejected with the reason, in Spanish,
 * for the import report. Values are trimmed; an empty value or the literal
 * NULL that phpMyAdmin writes is a missing value. Dates and timestamps come
 * back as CarbonImmutable in the app timezone (America/Bogota).
 */
final class CsvRow
{
    /**
     * Y-m-d, a space or T, H:i with optional seconds and fraction, and an
     * optional offset: Z, +00, +0000 or +00:00.
     */
    private const string TIMESTAMP = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.(\d+))?\s*(Z|[+-]\d{2}(?::?\d{2})?)?$/i';

    private const string DATE = '/^(\d{4})-(\d{2})-(\d{2})$/';

    /**
     * The zero date MySQL writes for a date it does not have.
     */
    private const string ZERO_DATE = '/^0000-00-00(?:[T ]00:00(?::00)?(?:\.0+)?)?$/';

    /**
     * @param  string  $file  The file name, without folder.
     * @param  int  $line  The line of the file where the row starts (the header is line 1).
     * @param  array<string, string>  $values  The value of each column the file was opened with.
     * @param  string|null  $problem  Why the row cannot be read at all, if it cannot.
     */
    public function __construct(
        public readonly string $file,
        public readonly int $line,
        private readonly array $values,
        private readonly ?string $problem = null,
    ) {}

    /**
     * A row that cannot be read (bad encoding, wrong number of columns).
     * Only its legacy id, when one could be read, is kept for the report.
     */
    public static function unreadable(string $file, int $line, string $problem, string $legacyId = ''): self
    {
        return new self($file, $line, $legacyId === '' ? [] : ['id' => $legacyId], $problem);
    }

    /**
     * @throws RowRejected When the row could not be read at all.
     */
    public function assertReadable(): void
    {
        if ($this->problem !== null) {
            throw new RowRejected($this->problem);
        }
    }

    /**
     * The "id" column as written in the legacy system, for the report; empty
     * when the file has no such column.
     */
    public function legacyId(): string
    {
        return trim($this->values['id'] ?? '');
    }

    public function string(string $column): string
    {
        return $this->optionalString($column) ?? throw $this->missing($column);
    }

    public function optionalString(string $column): ?string
    {
        $value = trim($this->raw($column));

        return $value === '' || $value === 'NULL' ? null : $value;
    }

    /**
     * A valid e-mail, trimmed and in lowercase like users.email.
     */
    public function email(string $column): string
    {
        $email = User::normalizeEmail($this->string($column));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RowRejected("correo inválido en {$column}: {$email}");
        }

        return $email;
    }

    /**
     * The entry of $allowed the value matches, ignoring case.
     *
     * @param  list<string>  $allowed
     */
    public function oneOf(string $column, array $allowed): string
    {
        $value = $this->string($column);

        foreach ($allowed as $option) {
            if (mb_strtolower($option) === mb_strtolower($value)) {
                return $option;
            }
        }

        throw new RowRejected(sprintf('valor no reconocido en %s: %s (se espera %s)', $column, $value, implode(', ', $allowed)));
    }

    /**
     * true, t or 1; false, f or 0 (as MySQL and Postgres export them).
     */
    public function bool(string $column): bool
    {
        $value = $this->string($column);

        return match (mb_strtolower($value)) {
            'true', 't', '1' => true,
            'false', 'f', '0' => false,
            default => throw new RowRejected("valor no reconocido en {$column}: {$value} (se espera true o false)"),
        };
    }

    public function int(string $column): int
    {
        return $this->optionalInt($column) ?? throw $this->missing($column);
    }

    public function optionalInt(string $column): ?int
    {
        $value = $this->optionalString($column);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) !== 1) {
            throw $this->invalidNumber($column, $value);
        }

        return (int) $value;
    }

    /**
     * A decimal number as written, with a dot and no thousands separator
     * (e.g. "2500000.50"); with $positive it must be greater than zero.
     */
    public function decimal(string $column, bool $positive = false): string
    {
        return $this->optionalDecimal($column, $positive) ?? throw $this->missing($column);
    }

    public function optionalDecimal(string $column, bool $positive = false): ?string
    {
        $value = $this->optionalString($column);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            throw $this->invalidNumber($column, $value);
        }

        if ($positive && (float) $value <= 0) {
            throw new RowRejected("el valor debe ser mayor que cero en {$column}: {$value}");
        }

        return $value;
    }

    /**
     * An integer array as Supabase exports it ({84,85}), as JSON ([84,85])
     * or plain (84,85); empty for a missing value. Repeated numbers count once.
     *
     * @return list<int>
     */
    public function intList(string $column): array
    {
        $value = $this->optionalString($column);
        $items = trim($value ?? '', '{}[] ');

        if ($items === '') {
            return [];
        }

        $numbers = [];

        foreach (explode(',', $items) as $item) {
            if (preg_match('/^\d+$/', trim($item)) !== 1) {
                throw new RowRejected("lista de números inválida en {$column}: {$value}");
            }

            $numbers[] = (int) trim($item);
        }

        return array_values(array_unique($numbers));
    }

    /**
     * A strict Y-m-d date, at the start of that day. A MySQL zero date
     * (0000-00-00) is an empty date: the optional getters return null and
     * the required ones reject the row.
     */
    public function date(string $column): CarbonImmutable
    {
        return $this->optionalDate($column) ?? throw $this->missingDate($column);
    }

    public function optionalDate(string $column): ?CarbonImmutable
    {
        $value = $this->optionalString($column);

        if ($value === null || preg_match(self::ZERO_DATE, $value) === 1) {
            return null;
        }

        if (preg_match(self::DATE, $value, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw $this->invalidDate($column, $value);
        }

        return CarbonImmutable::parse("{$value} 00:00:00", self::timezone());
    }

    /**
     * A moment written as Y-m-d H:i:s, with or without T, fraction and
     * offset. Without an offset it is Bogotá time, as Fase 1 stored it.
     */
    public function timestamp(string $column): CarbonImmutable
    {
        return $this->optionalTimestamp($column) ?? throw $this->missingDate($column);
    }

    public function optionalTimestamp(string $column): ?CarbonImmutable
    {
        $value = $this->optionalString($column);

        if ($value === null || preg_match(self::ZERO_DATE, $value) === 1) {
            return null;
        }

        if (preg_match(self::TIMESTAMP, $value, $parts) !== 1) {
            throw $this->invalidDate($column, $value);
        }

        [, $year, $month, $day, $hour, $minute] = $parts;
        $second = ($parts[6] ?? '') === '' ? '00' : $parts[6];

        if (! checkdate((int) $month, (int) $day, (int) $year) || (int) $hour > 23 || (int) $minute > 59 || (int) $second > 59) {
            throw $this->invalidDate($column, $value);
        }

        $micros = substr(str_pad($parts[7] ?? '', 6, '0'), 0, 6);
        $zone = self::offset($parts[8] ?? '') ?? self::timezone();

        return CarbonImmutable::parse("{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}.{$micros}", $zone)
            ->setTimezone(self::timezone());
    }

    /**
     * Z, +05, +0530 or +05:30 as a timezone name (+05:30); null when there
     * is no offset.
     */
    private static function offset(string $offset): ?string
    {
        if ($offset === '') {
            return null;
        }

        if (strtoupper($offset) === 'Z') {
            return '+00:00';
        }

        $digits = str_pad(str_replace(':', '', substr($offset, 1)), 4, '0');

        return $offset[0].substr($digits, 0, 2).':'.substr($digits, 2, 2);
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    private function raw(string $column): string
    {
        $this->assertReadable();

        if (! array_key_exists($column, $this->values)) {
            throw new LogicException("Column [{$column}] was not requested when {$this->file} was opened.");
        }

        return $this->values[$column];
    }

    private function missing(string $column): RowRejected
    {
        return new RowRejected("campo vacío: {$column}");
    }

    /**
     * Why a required date is missing: a zero date or an empty value.
     */
    private function missingDate(string $column): RowRejected
    {
        $value = $this->optionalString($column);

        return $value !== null && preg_match(self::ZERO_DATE, $value) === 1
            ? new RowRejected("fecha vacía: {$column}")
            : $this->missing($column);
    }

    private function invalidNumber(string $column, string $value): RowRejected
    {
        return new RowRejected("número inválido en {$column}: {$value}");
    }

    private function invalidDate(string $column, string $value): RowRejected
    {
        return new RowRejected("fecha inválida en {$column}: {$value}");
    }
}
