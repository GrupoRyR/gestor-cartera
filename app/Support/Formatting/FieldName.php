<?php

namespace App\Support\Formatting;

/**
 * Form field names in the two other shapes the form components need: the dot
 * key Laravel uses for validation errors and old input, and an HTML id.
 */
final class FieldName
{
    /**
     * clientes[0][email] → clientes.0.email; clientes[] → clientes.
     */
    public static function toKey(string $name): string
    {
        $key = str_replace(['[', ']'], ['.', ''], $name);

        return trim((string) preg_replace('/\.{2,}/', '.', $key), '.');
    }

    /**
     * clientes[0][email] → clientes-0-email. A value is appended so each
     * checkbox of a list gets its own id: clientes[] + C-001 → clientes-C-001.
     * A value that is not already id-safe also gets a short hash, so values
     * that differ only in punctuation keep distinct ids:
     * clientes[] + C900.123 → clientes-C900-123-<crc32b of "C900.123">.
     */
    public static function toId(string $name, ?string $value = null): string
    {
        $id = str_replace('.', '-', self::toKey($name));

        if ($value !== null) {
            $safe = self::sanitize($value);
            $id .= '-'.($safe === $value ? $safe : $safe.'-'.hash('crc32b', $value));
        }

        return self::sanitize($id);
    }

    /**
     * Keeps letters, digits, "_" and single "-" between them.
     */
    private static function sanitize(string $id): string
    {
        return trim((string) preg_replace(['/[^A-Za-z0-9_-]+/', '/-{2,}/'], '-', $id), '-');
    }
}
