<?php

namespace App\Support\Formatting;

use InvalidArgumentException;

/**
 * Money amounts as the user interface shows them (<x-ui.money>, exports).
 *
 * Colombian separators for every currency: "." for thousands and "," for
 * decimals. A no-break space (U+00A0) keeps the symbol next to the number
 * and the minus sign goes before the symbol: -$ 1.234.567.
 */
final class Money
{
    /**
     * Symbol and default decimals of each supported currency.
     *
     * @var array<string, array{symbol: string, decimals: int}>
     */
    private const array CURRENCIES = [
        'COP' => ['symbol' => '$', 'decimals' => 0],
        'USD' => ['symbol' => 'US$', 'decimals' => 2],
    ];

    /**
     * @param  int|float|string|null  $value  Amount; a string must be numeric ("1234567.89").
     * @param  string  $currency  COP or USD, in any case.
     * @param  int|null  $decimals  Null uses the currency default: 0 for COP, 2 for USD.
     * @param  string  $fallback  Returned for null and empty strings.
     *
     * @throws InvalidArgumentException When the value is not numeric, the currency is unknown or the decimals are negative.
     */
    public static function format(
        int|float|string|null $value,
        string $currency = 'COP',
        ?int $decimals = null,
        string $fallback = '—',
    ): string {
        $code = strtoupper($currency);

        if (! isset(self::CURRENCIES[$code])) {
            throw new InvalidArgumentException("Unknown currency [{$currency}]; use one of: ".implode(', ', array_keys(self::CURRENCIES)).'.');
        }

        if ($value === null || $value === '') {
            return $fallback;
        }

        if (is_string($value) && ! is_numeric($value)) {
            throw new InvalidArgumentException("The amount [{$value}] is not a number.");
        }

        $amount = (float) $value;

        if (! is_finite($amount)) {
            throw new InvalidArgumentException('The amount must be a finite number.');
        }

        $decimals ??= self::CURRENCIES[$code]['decimals'];

        if ($decimals < 0) {
            throw new InvalidArgumentException("Decimals must be zero or more, [{$decimals}] given.");
        }

        // Rounding first means a tiny negative amount shows as zero, not "-$ 0".
        $rounded = round($amount, $decimals);
        $sign = $rounded < 0 ? '-' : '';

        return $sign.self::CURRENCIES[$code]['symbol']."\u{00A0}".number_format(abs($rounded), $decimals, ',', '.');
    }
}
