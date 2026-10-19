<?php

use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\UnreadableCell;

describe('amount', function () {
    it('reads Colombian numbers: dot for thousands, comma for decimals', function (string $text, string $expected) {
        expect(Cells::amount($text, NumberStyle::Colombian))->toBe($expected);
    })->with([
        'with decimals' => ['1.234.567,89', '1234567.89'],
        'without decimals' => ['1.234.567', '1234567.00'],
        'plain digits' => ['450000', '450000.00'],
        'one decimal' => ['1.500,5', '1500.50'],
        'currency sign' => ['$ 2.000.000', '2000000.00'],
        'no-break space' => ["COP\u{00A0}12.500", '12500.00'],
        'negative' => ['-3.000,00', '-3000.00'],
        'negative in parentheses' => ['(3.000,00)', '-3000.00'],
        'negative with trailing minus' => ['3.000,00-', '-3000.00'],
        'less than one' => ['0,50', '0.50'],
    ]);

    it('reads international numbers: comma for thousands, dot for decimals', function (string $text, string $expected) {
        expect(Cells::amount($text, NumberStyle::International))->toBe($expected);
    })->with([
        'with thousands' => ['1,234,567.89', '1234567.89'],
        'plain with decimals' => ['1234567.00', '1234567.00'],
        'negative' => ['-1234.5', '-1234.50'],
        'currency sign' => ['US$ 9,999.99', '9999.99'],
    ]);

    it('reads numbers that the spreadsheet already typed', function () {
        expect(Cells::amount(1500, NumberStyle::Colombian))->toBe('1500.00')
            ->and(Cells::amount(1234.5, NumberStyle::International))->toBe('1234.50')
            ->and(Cells::amount(-10.255, NumberStyle::International))->toBe('-10.26');
    });

    it('returns null for an empty cell', function (mixed $cell) {
        expect(Cells::amount($cell, NumberStyle::Colombian))->toBeNull();
    })->with([null, '', '   ', "\u{00A0}"]);

    it('refuses what could be read two ways instead of guessing a 100 times larger amount', function (string $text, NumberStyle $style) {
        expect(fn () => Cells::amount($text, $style))->toThrow(UnreadableCell::class);
    })->with([
        'dot decimals in a Colombian file' => ['1234567.89', NumberStyle::Colombian],
        'comma decimals in an international file' => ['1234567,89', NumberStyle::International],
        'two decimal separators' => ['1,5,5', NumberStyle::Colombian],
        'text' => ['abono', NumberStyle::Colombian],
        'letters inside' => ['12a4', NumberStyle::Colombian],
    ]);
});

describe('date', function () {
    it('reads the formats of the bank', function (string $text, array $formats, string $expected) {
        expect(Cells::date($text, $formats)?->toDateString())->toBe($expected);
    })->with([
        'day first' => ['15/10/2026', ['d/m/Y'], '2026-10-15'],
        'day first without zeros' => ['5/1/2026', ['d/m/Y'], '2026-01-05'],
        'month first' => ['10/15/2026', ['m/d/Y'], '2026-10-15'],
        'year first with slashes' => ['2026/10/15', ['Y/m/d'], '2026-10-15'],
        'iso' => ['2026-10-15', ['d/m/Y', 'Y-m-d'], '2026-10-15'],
        'compact' => ['20261015', ['Ymd'], '2026-10-15'],
        'with a time' => ['15/10/2026 08:30', ['d/m/Y'], '2026-10-15'],
    ]);

    it('reads dates typed by the spreadsheet', function () {
        expect(Cells::date(new DateTimeImmutable('2026-10-15 00:00:00'), ['d/m/Y'])?->toDateString())->toBe('2026-10-15')
            ->and(Cells::date(46310, ['d/m/Y'])?->toDateString())->toBe('2026-10-15');
    });

    it('reads a date a spreadsheet stored as a plain number of digits', function () {
        expect(Cells::date(20261015, ['Ymd'])?->toDateString())->toBe('2026-10-15')
            ->and(Cells::date(20261015.0, ['Ymd'])?->toDateString())->toBe('2026-10-15')
            ->and(Cells::date(20261015, ['d/m/Y']))->toBeNull();
    });

    it('does not guess the order of day and month', function () {
        expect(Cells::date('10/15/2026', ['d/m/Y']))->toBeNull();
    });

    it('rejects days that do not exist', function () {
        expect(Cells::date('31/02/2026', ['d/m/Y']))->toBeNull();
    });

    it('returns null for an empty cell or text that is not a date', function (mixed $cell) {
        expect(Cells::date($cell, ['d/m/Y']))->toBeNull();
    })->with([null, '', 'Total', 'Saldo anterior']);

    it('gives dates at the start of the day in Bogotá', function () {
        $date = Cells::date('15/10/2026', ['d/m/Y']);

        expect($date?->timezoneName)->toBe('America/Bogota')
            ->and($date?->format('H:i:s'))->toBe('00:00:00');
    });
});

describe('header', function () {
    it('compares without accents, case, punctuation or extra spaces', function (mixed $cell, string $expected) {
        expect(Cells::header($cell))->toBe($expected);
    })->with([
        ['Descripción', 'descripcion'],
        ['  FECHA  ', 'fecha'],
        ['Débito ($)', 'debito'],
        ['Referencia 1', 'referencia 1'],
        ['N°. Documento', 'n documento'],
        ["Valor\u{00A0}Crédito", 'valor credito'],
        [null, ''],
        [12, '12'],
    ]);
});
