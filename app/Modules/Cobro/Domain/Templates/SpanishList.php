<?php

namespace App\Modules\Cobro\Domain\Templates;

/**
 * Joins items the way a sentence lists them in Spanish: "a", "a y b",
 * "a, b y c".
 */
final class SpanishList
{
    /**
     * @param  list<string>  $items
     */
    public static function join(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }

        $last = array_pop($items);

        return implode(', ', $items).' y '.$last;
    }
}
