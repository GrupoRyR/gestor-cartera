<?php

use App\Modules\Bancos\Domain\Statement\StatementMovement;
use App\Modules\Bancos\Domain\Statement\StatementReaders;
use App\Modules\Bancos\Domain\Statement\TabularStatementReader;
use App\Modules\Bancos\Domain\Statement\UnrecognizedStatement;
use App\Modules\Bancos\Infrastructure\Files\TabularFile;
use App\Modules\Core\Contracts\Enums\Bank;
use Tests\Modules\Bancos\Support\Fixtures;

/**
 * The movements of an invented statement as "Y-m-d amount description" lines.
 *
 * @param  list<StatementMovement>  $movements
 * @return list<string>
 */
function bancosLines(array $movements): array
{
    return array_map(
        fn (StatementMovement $m): string => "{$m->date->toDateString()} {$m->type->value} {$m->amount} {$m->currency} {$m->reference}",
        $movements,
    );
}

describe('the invented statement of each bank', function () {
    it('has a reader per bank, and it is the reader of that bank', function (Bank $bank) {
        expect(StatementReaders::for($bank)->bank())->toBe($bank);
    })->with(Bank::cases());

    it('reads Banco de Bogotá: a C/D indicator, plain numbers, Windows-1252 and the posting date', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read(TabularFile::rows(Fixtures::path(Bank::Bogota)));

        expect(bancosLines($reading->movements))->toBe([
            '2026-10-13 credito 1250000.00 COP NSDISF000000PGPF1',
            '2026-10-13 debito 5000.00 COP NSDISF000000PGPF1', // the GMF of that credit shares its reference
            '2026-10-13 credito 480500.50 COP NSDISF000000PGPF2',
            '2026-10-14 credito 300000.00 COP NSDISF000000PGPF3',
            '2026-10-14 debito 1667.98 COP NSDISF000000PGPF3',
            '2026-10-14 debito 200000.00 COP NSDISF000000PGPF4',
            '2026-10-14 debito 800.00 COP NSDISF000000PGPF4',
        ])
            ->and($reading->movements[0]->account)->toBe('0000012345')
            ->and(count($reading->credits()))->toBe(3)
            ->and($reading->debitCount())->toBe(4)
            ->and($reading->errors)->toBe([])
            ->and($reading->ignoredRows)->toBe(0);
    });

    it('joins the Bogotá description with the reference text, without its noise', function () {
        $descriptions = array_map(
            fn (StatementMovement $m): ?string => $m->description,
            StatementReaders::for(Bank::Bogota)->read(TabularFile::rows(Fixtures::path(Bank::Bogota)))->movements,
        );

        expect($descriptions[0])->toBe('Abono dispersion ACH · Cr Ach Bancolombia Ferreteria El Tornillo Nit900111222 Fac 1234')
            ->and($descriptions[1])->toBe('Gravamen Movimientos Financieros')
            ->and($descriptions[2])->toBe('Abono transferencia electronica · Abono transferencia por canal electronico el 13/10/2026 desde 00011122233 FERRETERIA EL TORNILLO,SAS')
            ->and($descriptions[3])->toBe('Abono recaudo con comprobante · Abono por recaudos con comprobante 900111222 Referencia 2: 900111222');
    });

    it('takes the Bogotá date from Fecha, never from Fecha de Transacción', function (string $transactionDate) {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['Fecha de Transacción', 'Valor', 'Indicador Débito / Crédito', 'Fecha'],
            [$transactionDate, '1000', 'C', '15/10/2026'],
        ]);

        expect(bancosLines($reading->movements))->toBe(['2026-10-15 credito 1000.00 COP ']);
    })->with(['empty' => '', 'another date' => '01/10/2026']);

    it('never returns a description longer than the 500 characters the table keeps', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['Fecha', 'Valor', 'Indicador Débito / Crédito', 'Descripción de Transacción', 'Texto de Referencia'],
            ['15/10/2026', '1000', 'C', 'Abono', str_repeat('ñ', 600)],
        ]);

        expect(mb_strlen((string) $reading->movements[0]->description))->toBe(TabularStatementReader::MAX_DESCRIPTION);
    });

    it('reads Davivienda: an HTML .xls, Notas Credito or Debito, Colombian numbers, the totals left out', function () {
        $reading = StatementReaders::for(Bank::Davivienda)->read(TabularFile::rows(Fixtures::path(Bank::Davivienda)));

        expect(bancosLines($reading->movements))->toBe([
            '2026-10-13 credito 1250000.00 COP 123456',
            '2026-10-13 debito 5000.00 COP 99999999',
            '2026-10-14 credito 640000.50 COP 654321',
            '2026-10-14 debito 295000.00 COP 99999998',
        ])
            ->and($reading->movements[0]->description)->toBe('Abono ACH 900111222 FERRETERIA EL TORN')
            ->and(count($reading->credits()))->toBe(2)
            ->and($reading->debitCount())->toBe(2)
            ->and($reading->errors)->toBe([])
            ->and($reading->ignoredRows)->toBe(2); // the titles and the values of the totals at the foot
    });

    it('reads Bancolombia: rows without a header, by position, a signed amount and ddmmaaaa dates', function () {
        $reading = StatementReaders::for(Bank::Bancolombia)->read(TabularFile::rows(Fixtures::path(Bank::Bancolombia)));

        expect(bancosLines($reading->movements))->toBe([
            '2026-10-13 credito 2225395.00 COP ',
            '2026-10-13 credito 500000.00 COP ',
            '2026-10-13 credito 500000.00 COP ',
            '2026-10-13 debito 672000.00 COP 000123456',
            '2026-10-14 credito 780000.00 COP ',
            '2026-10-14 debito 2688.00 COP ',
            '2026-10-14 debito 15000.00 COP ',
        ])
            ->and($reading->movements[0]->description)->toBe('TRANSFERENCIA CTA SUC VIRTUAL')
            ->and($reading->movements[0]->account)->toBe('00000000000')
            ->and($reading->movements[0]->reference)->toBeNull()
            ->and($reading->movements[4]->description)->toBe('PAGO INTERBANC FERRETERIA EL T')
            ->and(count($reading->credits()))->toBe(4)
            ->and($reading->debitCount())->toBe(3)
            ->and($reading->errors)->toBe([])
            ->and($reading->ignoredRows)->toBe(0);
    });

    it('reads the Miami account: English headers, month-first dates and dollars', function () {
        $reading = StatementReaders::for(Bank::Miami)->read(TabularFile::rows(Fixtures::path(Bank::Miami)));

        expect(bancosLines($reading->credits()))->toBe([
            '2026-10-13 credito 12500.00 USD WIRE-0042',
            '2026-10-15 credito 3200.50 USD ACH-7781',
        ])
            ->and($reading->debitCount())->toBe(1)
            ->and($reading->errors)->toBe([]);
    });

    it('lets the account currency be set when the usual one is not right', function () {
        $reading = StatementReaders::for(Bank::Miami, 'cop')->read(TabularFile::rows(Fixtures::path(Bank::Miami)));

        expect($reading->credits()[0]->currency)->toBe('COP');
    });
});

describe('finding the columns', function () {
    it('finds the header below a title and the account data', function () {
        $reading = StatementReaders::for(Bank::Miami)->read([
            ['Sample bank'],
            [],
            ['Account', '123'],
            ['Date', 'Description', 'Reference', 'Withdrawals', 'Deposits'],
            ['10/15/2026', 'Payment', 'R1', null, '1,000.00'],
        ]);

        expect(bancosLines($reading->movements))->toBe(['2026-10-15 credito 1000.00 USD R1']);
    });

    it('does not depend on the order of the columns', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['VALOR', 'Referencia del Banco', 'Fecha', 'INDICADOR DÉBITO / CRÉDITO'],
            ['2500', 'R9', '16/10/2026', 'C'],
        ]);

        expect(bancosLines($reading->movements))->toBe(['2026-10-16 credito 2500.00 COP R9']);
    });

    it('says which bank it could not recognize', function () {
        expect(fn () => StatementReaders::for(Bank::Davivienda)->read([['Nombre', 'Edad'], ['Ana', '30']]))
            ->toThrow(UnrecognizedStatement::class, 'Davivienda');
    });

    it('refuses an empty file', function (Bank $bank) {
        expect(fn () => StatementReaders::for($bank)->read([[], ['']]))->toThrow(UnrecognizedStatement::class);
    })->with([Bank::Bogota, Bank::Bancolombia]);
});

describe('a statement without a header, read by position', function () {
    it('refuses a file that is not of that bank and says what it expected', function () {
        $rows = TabularFile::rows(Fixtures::path(Bank::Miami));

        expect(fn () => StatementReaders::for(Bank::Bancolombia)->read($rows))
            ->toThrow(UnrecognizedStatement::class, 'Bancolombia')
            ->and(fn () => StatementReaders::for(Bank::Bancolombia)->read($rows))
            ->toThrow(UnrecognizedStatement::class, 'ddmmaaaa');
    });

    it('reports a short row with its number instead of skipping it', function () {
        $reading = StatementReaders::for(Bank::Bancolombia)->read([
            ['00000000000', '126', '1', '13102026', '', '1000.00', '4160', 'TRANSFERENCIA', '0'],
            [],
            ['00000000000', '126', '1', '13102026', '', '2000.00'],
            ['00000000000', '126', '1', '14102026', '', '3000.00', '4160', 'TRANSFERENCIA', '0'],
        ]);

        expect(bancosLines($reading->movements))->toBe(['2026-10-13 credito 1000.00 COP ', '2026-10-14 credito 3000.00 COP '])
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->row)->toBe(3)
            ->and($reading->errors[0]->message)->toContain('6');
    });

    it('reports a date it cannot read in a row of the statement', function () {
        $reading = StatementReaders::for(Bank::Bancolombia)->read([
            ['00000000000', '126', '1', '13102026', '', '1000.00', '4160', 'TRANSFERENCIA', '0'],
            ['00000000000', '126', '1', '31022026', '', '2000.00', '4160', 'TRANSFERENCIA', '0'],
        ]);

        expect($reading->movements)->toHaveCount(1)
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->row)->toBe(2)
            ->and($reading->errors[0]->message)->toContain('31022026');
    });
});

describe('rows that are not movements', function () {
    it('reports a bad amount with its row number and keeps reading', function () {
        // Davivienda prints Colombian numbers: a dot only groups thousands.
        $reading = StatementReaders::for(Bank::Davivienda)->read([
            ['Fecha', 'Tran', 'Valor Total'],
            ['15/10/2026', 'Notas Credito', '$ 1.000,00'],
            ['15/10/2026', 'Notas Credito', '1000.55'],
            ['16/10/2026', 'Notas Credito', '$ 2.000,00'],
        ]);

        expect(count($reading->credits()))->toBe(2)
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->row)->toBe(3)
            ->and($reading->errors[0]->message)->toContain('1000.55');
    });

    it('reports a date that went wrong, but ignores notes without a date', function () {
        $reading = StatementReaders::for(Bank::Davivienda)->read([
            ['Fecha', 'Tran', 'Valor Total'],
            ['31/02/2026', 'Notas Credito', '1.000,00'],
            ['Saldo anterior', 'Notas Credito', '9.000,00'],
            [null, 'Nota', null],
        ]);

        expect($reading->movements)->toBe([])
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->row)->toBe(2)
            ->and($reading->ignoredRows)->toBe(2);
    });

    it('ignores rows with a zero amount', function () {
        $reading = StatementReaders::for(Bank::Miami)->read([
            ['Date', 'Withdrawals', 'Deposits'],
            ['10/15/2026', '0.00', '0.00'],
        ]);

        expect($reading->movements)->toBe([])
            ->and($reading->ignoredRows)->toBe(1);
    });

    it('does not guess when it cannot tell a credit from a debit', function () {
        $reading = StatementReaders::for(Bank::Davivienda)->read([
            ['Fecha', 'Tran', 'Valor Total'],
            ['15/10/2026', 'Reverso', '1.000,00'],
        ]);

        expect($reading->movements)->toBe([])
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->message)->toContain('Reverso');
    });
});

describe('from an Excel file', function () {
    it('reads typed dates and numbers', function () {
        $path = Fixtures::workbook([
            ['Fecha', 'Descripción de Transacción', 'Referencia del Banco', 'Indicador Débito / Crédito', 'Valor'],
            [new DateTimeImmutable('2026-10-15'), 'Pago cliente', 'R1', 'C', 1250000.5],
            ['16/10/2026', 'Pago cliente 2', 'R2', 'C', '3000.00'],
        ]);

        try {
            $reading = StatementReaders::for(Bank::Bogota)->read(TabularFile::rows($path));
        } finally {
            unlink($path);
        }

        expect(bancosLines($reading->credits()))->toBe([
            '2026-10-15 credito 1250000.50 COP R1',
            '2026-10-16 credito 3000.00 COP R2',
        ]);
    });
});
