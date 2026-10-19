<?php

namespace Tests\Modules\Cobro\Support;

/**
 * The text a person reads in an HTML fragment: tags stripped, entities
 * decoded (after stripping, so an escaped "<" stays text), non-breaking
 * spaces as spaces and every run of white space collapsed.
 */
final class VisibleText
{
    public static function of(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
