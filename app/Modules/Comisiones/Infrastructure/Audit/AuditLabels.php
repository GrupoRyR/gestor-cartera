<?php

namespace App\Modules\Comisiones\Infrastructure\Audit;

use Stringable;

/**
 * How the audit screen (§7.9) reads a row of comisiones_bitacora: the
 * Spanish label of each event and subject type (the name itself when there
 * is none), and the stored changes and context as short lines.
 */
final class AuditLabels
{
    private const string PREFIX = 'comisiones.';

    /** "Presupuesto editado" for comisiones.budget.updated; the name for an unknown event. */
    public static function event(string $event): string
    {
        return str_starts_with($event, self::PREFIX)
            ? self::translated('comisiones::auditoria.eventos.'.substr($event, strlen(self::PREFIX))) ?? $event
            : $event;
    }

    /** "OBR #3", "Liquidación #12"; null without subject. */
    public static function subject(?string $type, ?string $id): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $label = str_contains($type, '.') ? $type : self::translated('comisiones::auditoria.sujetos.'.$type) ?? $type;

        return $id === null || $id === '' ? $label : "{$label} #{$id}";
    }

    /**
     * The changes as "field: old → new" (or "field: value") lines.
     *
     * @param  array<mixed>  $changes
     * @return list<array{field: string, value: string}>
     */
    public static function changes(array $changes): array
    {
        $lines = [];

        foreach ($changes as $field => $change) {
            $lines[] = [
                'field' => (string) $field,
                'value' => is_array($change) && (array_key_exists('old', $change) || array_key_exists('new', $change))
                    ? self::value($change['old'] ?? null).' → '.self::value($change['new'] ?? null)
                    : self::value($change),
            ];
        }

        return $lines;
    }

    /**
     * The context as "key: value" lines.
     *
     * @param  array<mixed>  $context
     * @return list<string>
     */
    public static function context(array $context): array
    {
        $lines = [];

        foreach ($context as $key => $value) {
            $lines[] = $key.': '.self::value($value);
        }

        return $lines;
    }

    /** The text of a translation key, null when there is none (or it names a group). */
    private static function translated(string $key): ?string
    {
        $text = __($key);

        return is_string($text) && $text !== $key ? $text : null;
    }

    /** A stored value as text: «—» for none, JSON for a list or a map. */
    public static function value(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '—',
            is_bool($value) => $value ? 'sí' : 'no',
            is_scalar($value), $value instanceof Stringable => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
