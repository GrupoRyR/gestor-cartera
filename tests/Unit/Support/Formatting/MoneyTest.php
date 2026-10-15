<?php

use App\Support\Formatting\Money;

// Money::format() separates the symbol from the number with a no-break
// space (U+00A0), written below as \u{00A0}.

it('formats Colombian pesos with dots for thousands and no decimals', function () {
    expect(Money::format(1234567))->toBe("\$\u{00A0}1.234.567")
        ->and(Money::format(0))->toBe("\$\u{00A0}0")
        ->and(Money::format(999))->toBe("\$\u{00A0}999");
});

it('rounds pesos to whole numbers by default', function () {
    expect(Money::format(1234567.5))->toBe("\$\u{00A0}1.234.568")
        ->and(Money::format('1234567.49'))->toBe("\$\u{00A0}1.234.567");
});

it('puts the minus sign before the symbol', function () {
    expect(Money::format(-1234567))->toBe("-\$\u{00A0}1.234.567")
        ->and(Money::format('-250000'))->toBe("-\$\u{00A0}250.000");
});

it('never shows a negative zero', function () {
    expect(Money::format(-0.4))->toBe("\$\u{00A0}0")
        ->and(Money::format(-0.001, 'USD'))->toBe("US\$\u{00A0}0,00");
});

it('formats dollars with two decimals and Colombian separators', function () {
    expect(Money::format(1234.5, 'USD'))->toBe("US\$\u{00A0}1.234,50")
        ->and(Money::format(-15, 'USD'))->toBe("-US\$\u{00A0}15,00");
});

it('accepts an explicit number of decimals', function () {
    expect(Money::format(1234.567, 'COP', 2))->toBe("\$\u{00A0}1.234,57")
        ->and(Money::format(1234.567, 'USD', 0))->toBe("US\$\u{00A0}1.235");
});

it('accepts the currency code in any case', function () {
    expect(Money::format(10, 'usd'))->toBe("US\$\u{00A0}10,00");
});

it('returns the fallback for empty values', function () {
    expect(Money::format(null))->toBe('—')
        ->and(Money::format(''))->toBe('—')
        ->and(Money::format(null, 'USD', null, 'Sin dato'))->toBe('Sin dato');
});

it('rejects values that are not numbers', function () {
    Money::format('mil pesos');
})->throws(InvalidArgumentException::class, 'mil pesos');

it('rejects infinite numbers', function () {
    Money::format(INF);
})->throws(InvalidArgumentException::class);

it('rejects unknown currencies', function () {
    Money::format(100, 'EUR');
})->throws(InvalidArgumentException::class, 'EUR');

it('rejects a negative number of decimals', function () {
    Money::format(100, 'COP', -1);
})->throws(InvalidArgumentException::class);
