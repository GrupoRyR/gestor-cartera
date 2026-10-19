<?php

use App\Modules\Bancos\Domain\Statement\StatementMovement;
use App\Modules\Bancos\Domain\Statement\StatementReaders;
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

    it('reads Banco de Bogotá: separate credit and debit columns, ISO-8859-1, semicolons', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read(TabularFile::rows(Fixtures::path(Bank::Bogota)));

        expect(bancosLines($reading->movements))->toBe([
            '2026-10-05 credito 1250000.00 COP ACH123456',
            '2026-10-05 debito 300000.00 COP PP-889',
            '2026-10-06 credito 480500.50 COP 998877',
            '2026-10-06 credito 480500.50 COP 998877',
            '2026-10-07 debito 12000.00 COP ',
            '2026-10-08 credito 1234.56 COP ',
        ])
            ->and($reading->movements[0]->description)->toBe('TRANSFERENCIA ACH DE FERRETERIA EL TORNILLO SAS')
            ->and(count($reading->credits()))->toBe(4)
            ->and($reading->debitCount())->toBe(2)
            ->and($reading->errors)->toBe([])
            ->and($reading->ignoredRows)->toBe(1); // the "Total" row
    });

    it('reads Davivienda: one amount column and a column that says credit or debit', function () {
        $reading = StatementReaders::for(Bank::Davivienda)->read(TabularFile::rows(Fixtures::path(Bank::Davivienda)));

        expect(bancosLines($reading->credits()))->toBe([
            '2026-10-12 credito 2800000.00 COP 45001',
            '2026-10-13 credito 640000.00 COP 45003',
        ])
            ->and($reading->debitCount())->toBe(2)
            ->and($reading->errors)->toBe([]);
    });

    it('reads Bancolombia: a signed amount column, year-first dates, a BOM and quoted commas', function () {
        $reading = StatementReaders::for(Bank::Bancolombia)->read(TabularFile::rows(Fixtures::path(Bank::Bancolombia)));

        expect(bancosLines($reading->credits()))->toBe([
            '2026-10-13 credito 3500000.00 COP PSE778899',
            '2026-10-14 credito 780000.00 COP TR-5521',
        ])
            ->and($reading->credits()[1]->description)->toBe('TRANSFERENCIA DE SERVICIOS, LOGISTICA SAS')
            ->and($reading->debitCount())->toBe(2)
            ->and($reading->errors)->toBe([]);
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
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['Banco de Bogotá'],
            [],
            ['Cuenta', '123'],
            ['Fecha', 'Descripción', 'Referencia', 'Débito', 'Crédito'],
            ['15/10/2026', 'Pago', 'R1', null, '1.000,00'],
        ]);

        expect(bancosLines($reading->movements))->toBe(['2026-10-15 credito 1000.00 COP R1']);
    });

    it('does not depend on the order of the columns', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['CRÉDITO', 'Referencia', 'Fecha'],
            ['2.500,00', 'R9', '16/10/2026'],
        ]);

        expect(bancosLines($reading->movements))->toBe(['2026-10-16 credito 2500.00 COP R9']);
    });

    it('says which bank it could not recognize', function () {
        expect(fn () => StatementReaders::for(Bank::Davivienda)->read([['Nombre', 'Edad'], ['Ana', '30']]))
            ->toThrow(UnrecognizedStatement::class, 'Davivienda');
    });

    it('refuses an empty file', function () {
        expect(fn () => StatementReaders::for(Bank::Bogota)->read([]))->toThrow(UnrecognizedStatement::class);
    });
});

describe('rows that are not movements', function () {
    it('reports a bad amount with its row number and keeps reading', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['Fecha', 'Descripción', 'Débito', 'Crédito'],
            ['15/10/2026', 'Bueno', null, '1.000,00'],
            ['15/10/2026', 'Malo', null, '1000.55'],
            ['16/10/2026', 'Bueno 2', null, '2.000,00'],
        ]);

        expect(count($reading->credits()))->toBe(2)
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->row)->toBe(3)
            ->and($reading->errors[0]->message)->toContain('1000.55');
    });

    it('reports a date that went wrong, but ignores notes without a date', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['Fecha', 'Descripción', 'Débito', 'Crédito'],
            ['31/02/2026', 'Fecha imposible', null, '1.000,00'],
            ['Saldo anterior', null, null, '9.000,00'],
            [null, 'Nota', null, null],
        ]);

        expect($reading->movements)->toBe([])
            ->and($reading->errors)->toHaveCount(1)
            ->and($reading->errors[0]->row)->toBe(2)
            ->and($reading->ignoredRows)->toBe(2);
    });

    it('ignores rows with a zero amount', function () {
        $reading = StatementReaders::for(Bank::Bogota)->read([
            ['Fecha', 'Débito', 'Crédito'],
            ['15/10/2026', '0,00', '0,00'],
        ]);

        expect($reading->movements)->toBe([])
            ->and($reading->ignoredRows)->toBe(1);
    });

    it('does not guess when it cannot tell a credit from a debit', function () {
        $reading = StatementReaders::for(Bank::Davivienda)->read([
            ['Fecha', 'Transacción', 'Valor'],
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
            ['EXTRACTO DE EJEMPLO INVENTADO'],
            ['Fecha', 'Descripción', 'Referencia', 'Débito', 'Crédito'],
            [new DateTimeImmutable('2026-10-15'), 'Pago cliente', 'R1', '', 1250000.5],
            ['16/10/2026', 'Pago cliente 2', 'R2', '', '3.000,00'],
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
