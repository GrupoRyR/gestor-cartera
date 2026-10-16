<?php

namespace App\Modules\Asesores\Infrastructure\Export;

/**
 * A column of an exported sheet: its title, how wide it is and what kind of
 * value it holds, which decides the cell format.
 */
final readonly class Column
{
    public const string TEXT = 'text';

    public const string MONEY = 'money';

    public const string DATE = 'date';

    public const string NUMBER = 'number';

    private function __construct(
        public string $title,
        public float $width,
        public string $kind,
    ) {}

    public static function text(string $title, float $width = 20): self
    {
        return new self($title, $width, self::TEXT);
    }

    /**
     * Pesos, as a number Excel can add up, shown with thousands separators.
     */
    public static function money(string $title, float $width = 16): self
    {
        return new self($title, $width, self::MONEY);
    }

    public static function date(string $title, float $width = 14): self
    {
        return new self($title, $width, self::DATE);
    }

    public static function number(string $title, float $width = 12): self
    {
        return new self($title, $width, self::NUMBER);
    }
}
