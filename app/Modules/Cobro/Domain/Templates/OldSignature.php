<?php

namespace App\Modules\Cobro\Domain\Templates;

/**
 * The written signatures the default templates ended with before every
 * mail got the company's signature (cobro::correos.firma). Used once, to
 * cut them off the saved templates (migration 2026_10_05_000009).
 */
final class OldSignature
{
    /**
     * The closing signatures of the weekly collection and circularization
     * defaults of Segurex and Derivados, word for word.
     *
     * @var list<string>
     */
    public const array SIGNATURES = [
        "**Cordialmente,**\n\n**María Paula Alvarez**\n**Auxiliar de Cartera**",
        "Cordialmente,\n\n**María Paula Alvarez**\n**Auxiliar de Crédito y Cobranza**\nCelular: +57 3153654788\nE-mail: cartera@derivadoscol.com",
        "Cordialmente,\n\n**María Paula Alvarez**\nAuxiliar de Cartera",
        "**Cordialmente,**\n\n**María Paula Alvarez**\n**Auxiliar de Crédito y Cobranza**\n**Celular: +57 3153654788**\n**E-mail: cartera@derivadoscol.com**",
    ];

    /**
     * $closing without its old signature, when it ends with one of them
     * exactly (line breaks and trailing spaces aside); null otherwise.
     */
    public static function cut(string $closing): ?string
    {
        $text = rtrim(implode("\n", array_map('rtrim', explode("\n", str_replace(["\r\n", "\r"], "\n", $closing)))));

        foreach (self::SIGNATURES as $signature) {
            if ($text === $signature) {
                return '';
            }

            if (str_ends_with($text, "\n\n".$signature)) {
                return rtrim(substr($text, 0, -strlen("\n\n".$signature)));
            }
        }

        return null;
    }

    /**
     * Whether $closing still seems to carry a written signature.
     */
    public static function seemsSigned(string $closing): bool
    {
        return mb_stripos($closing, 'cordialmente') !== false;
    }
}
