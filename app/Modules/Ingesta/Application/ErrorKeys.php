<?php

namespace App\Modules\Ingesta\Application;

/**
 * How the error keys of BatchValidator read on screen: "rows.3.saldo"
 * becomes «Fila 3, columna saldo». Rows keep their 0-based number, as in
 * the messages themselves.
 */
final class ErrorKeys
{
    public static function describe(string $key): string
    {
        if (preg_match('/^rows\.(\d+)(?:\.(.+))?$/', $key, $parts) === 1) {
            return isset($parts[2])
                ? __('ingesta::historial.campos.fila_columna', ['fila' => $parts[1], 'columna' => $parts[2]])
                : __('ingesta::historial.campos.fila', ['fila' => $parts[1]]);
        }

        $translation = "ingesta::historial.campos.{$key}";

        return in_array($key, ['batch_id', 'cutoff_date', 'rows', 'body'], true) ? __($translation) : $key;
    }
}
