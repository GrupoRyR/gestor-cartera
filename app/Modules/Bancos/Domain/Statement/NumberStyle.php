<?php

namespace App\Modules\Bancos\Domain\Statement;

/**
 * How a bank prints amounts. It is a property of the bank, never detected from
 * the number: "1.234" is a thousand two hundred thirty-four in one style and a
 * little over one in the other.
 */
enum NumberStyle
{
    /** 1.234.567,89: dot for thousands, comma for decimals. */
    case Colombian;

    /** 1,234,567.89: comma for thousands, dot for decimals. */
    case International;

    public function thousands(): string
    {
        return match ($this) {
            self::Colombian => '.',
            self::International => ',',
        };
    }

    public function decimal(): string
    {
        return match ($this) {
            self::Colombian => ',',
            self::International => '.',
        };
    }
}
