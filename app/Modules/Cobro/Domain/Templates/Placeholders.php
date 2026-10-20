<?php

namespace App\Modules\Cobro\Domain\Templates;

/**
 * The {placeholders} a template text may use and their replacement. Texts
 * are plain (only **bold** is markup): the renderer escapes the result, so
 * a value can never inject HTML into the mail.
 */
final class Placeholders
{
    /**
     * Placeholder => what it stands for (shown on the template screen).
     *
     * @var array<string, string>
     */
    public const array ALLOWED = [
        'cliente' => 'Nombre del cliente',
        'nit' => 'NIT del cliente',
        'empresa' => 'Nombre de tu empresa',
        'total_vencido' => 'Total vencido, p. ej. $ 4.500.000',
        'facturas' => 'Número de facturas vencidas',
        'factura_o_facturas' => '«la factura» o «las facturas», según cuántas estén vencidas',
        'numeros_facturas' => 'Números de las facturas vencidas, p. ej. 9952 y 10403',
        'vencimientos' => 'Fechas de vencimiento de las facturas vencidas, p. ej. 26/09/2026 y 25/10/2026',
        'fecha_corte' => 'Fecha de corte de la cartera, DD/MM/AAAA',
    ];

    /**
     * Replaces each known {placeholder} with its value; unknown ones stay.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{([a-z_]+)\}/',
            fn (array $match): string => array_key_exists($match[1], self::ALLOWED) && array_key_exists($match[1], $values)
                ? $values[$match[1]]
                : $match[0],
            $text,
        );
    }

    /**
     * The {placeholders} of $text that are not allowed, in order.
     *
     * @return list<string>
     */
    public static function unknown(string $text): array
    {
        preg_match_all('/\{([^{}\s]*)\}/', $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            fn (string $name): bool => ! array_key_exists($name, self::ALLOWED),
        )));
    }
}
