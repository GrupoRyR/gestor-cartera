<?php

namespace App\Modules\Cobro\Domain\Mail;

/**
 * RFC 5322 Message-IDs ("<local@domain>") quoted in a text, such as the
 * original headers a bounce report includes.
 */
final class MessageIds
{
    /**
     * Unique Message-IDs in the order they appear, angle brackets included.
     *
     * @return list<string>
     */
    public static function in(string $text): array
    {
        preg_match_all('/<[^<>\s@]+@[^<>\s@]+>/', $text, $matches);

        return array_values(array_unique($matches[0]));
    }
}
