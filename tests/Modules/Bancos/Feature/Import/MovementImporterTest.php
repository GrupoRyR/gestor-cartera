<?php

use App\Modules\Bancos\Application\Import\ImportResult;
use App\Modules\Bancos\Application\Import\MovementImporter;
use App\Modules\Bancos\Application\Import\StatementImport;
use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Domain\Movement\MovementType;
use App\Modules\Bancos\Domain\Statement\RowError;
use App\Modules\Bancos\Domain\Statement\StatementMovement;
use App\Modules\Bancos\Domain\Statement\StatementReading;
use App\Modules\Bancos\Domain\Statement\UnrecognizedStatement;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Bancos\Support\Fixtures;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * A movement of the day with the given amount.
 */
function bancosMovement(string $amount = '1000.00', MovementType $type = MovementType::Credit, string $date = '2026-10-15', ?string $reference = 'R1', ?string $description = 'Pago'): StatementMovement
{
    return new StatementMovement(
        date: CarbonImmutable::parse($date, 'America/Bogota'),
        type: $type,
        amount: $amount,
        description: $description,
        reference: $reference,
    );
}

/**
 * @param  list<StatementMovement>  $movements
 */
function bancosImport(array $movements, Bank $bank = Bank::Bogota, CompanyCode $company = CompanyCode::SGX, ?StatementReading $reading = null): ImportResult
{
    return app(MovementImporter::class)->import($bank, $company, $reading ?? new StatementReading($movements), ImportSource::Folder, 'extracto.csv', str_repeat('a', 64), null);
}

it('stores the credits as pending and leaves the debits out', function () {
    $result = bancosImport([
        bancosMovement('1500000.50'),
        bancosMovement('300000.00', MovementType::Debit, reference: 'PP-1'),
    ]);

    expect(BankMovement::query()->count())->toBe(1);

    $stored = BankMovement::query()->sole();

    expect($stored->amount)->toBe('1500000.50')
        ->and($stored->status)->toBe(MovementStatus::Pending)
        ->and($stored->company)->toBe(CompanyCode::SGX)
        ->and($stored->bank)->toBe(Bank::Bogota)
        ->and($stored->movement_date->toDateString())->toBe('2026-10-15')
        ->and($stored->currency)->toBe('COP')
        ->and($stored->receipt_number)->toBeNull()
        ->and($result->inserted())->toBe(1)
        ->and($result->load->debits_skipped)->toBe(1)
        ->and($result->load->movements_read)->toBe(2);
});

it('records where the statement came from', function () {
    $load = bancosImport([bancosMovement()])->load->fresh();

    expect($load->source)->toBe(ImportSource::Folder)
        ->and($load->file_name)->toBe('extracto.csv')
        ->and($load->file_sha256)->toBe(str_repeat('a', 64))
        ->and($load->bank)->toBe(Bank::Bogota)
        ->and($load->company)->toBe(CompanyCode::SGX);
});

it('does not duplicate a statement that is loaded twice', function () {
    $movements = [bancosMovement('1000.00', reference: 'A'), bancosMovement('2000.00', reference: 'B')];

    bancosImport($movements);
    $second = bancosImport($movements);

    expect(BankMovement::query()->count())->toBe(2)
        ->and($second->inserted())->toBe(0)
        ->and($second->duplicates())->toBe(2);
});

it('keeps two identical payments of the same file, and still loads that file only once', function () {
    $twins = [bancosMovement('480500.50', reference: '998877'), bancosMovement('480500.50', reference: '998877')];

    bancosImport($twins);
    bancosImport($twins);

    expect(BankMovement::query()->count())->toBe(2);
});

it('adds only the new credits of an overlapping statement', function () {
    bancosImport([bancosMovement('1000.00', reference: 'A'), bancosMovement('2000.00', reference: 'B')]);

    $overlap = bancosImport([
        bancosMovement('1000.00', reference: 'A'),
        bancosMovement('2000.00', reference: 'B'),
        bancosMovement('3000.00', reference: 'C', date: '2026-10-16'),
    ]);

    expect(BankMovement::query()->count())->toBe(3)
        ->and($overlap->inserted())->toBe(1)
        ->and($overlap->duplicates())->toBe(2);
});

it('does not mind case or extra spaces in the texts when it looks for duplicates', function () {
    bancosImport([bancosMovement(description: 'Pago  Cliente SAS')]);
    bancosImport([bancosMovement(description: ' pago cliente sas ')]);

    expect(BankMovement::query()->count())->toBe(1);
});

it('keeps the same movement of two companies or two banks apart', function () {
    bancosImport([bancosMovement()], Bank::Bogota, CompanyCode::SGX);
    bancosImport([bancosMovement()], Bank::Bogota, CompanyCode::SDC);
    bancosImport([bancosMovement()], Bank::Davivienda, CompanyCode::SGX);

    expect(BankMovement::query()->count())->toBe(3);
});

it('does not store credits dated after today, which is a date read in the wrong order', function () {
    $result = bancosImport([
        bancosMovement('1000.00', date: '2026-10-15'),
        bancosMovement('2000.00', date: '2026-12-03', reference: 'R2'),
    ]);

    expect(BankMovement::query()->count())->toBe(1)
        ->and($result->hasErrors())->toBeTrue()
        ->and($result->load->errors[0]['message'])->toContain('03/12/2026');
});

it('accepts a credit dated today', function () {
    bancosImport([bancosMovement(date: '2026-10-16')]);

    expect(BankMovement::query()->count())->toBe(1);
});

it('keeps the rows the reader could not read on the load', function () {
    $reading = new StatementReading(
        [bancosMovement()],
        [new RowError(7, 'La fecha «31/02/2026» no es válida.')],
        ignoredRows: 2,
    );

    $load = bancosImport([], reading: $reading)->load->fresh();

    expect($load->error_count)->toBe(1)
        ->and($load->errors)->toBe([['row' => 7, 'message' => 'La fecha «31/02/2026» no es válida.']])
        ->and($load->ignored_rows)->toBe(2);
});

it('stores at most 50 errors but counts them all', function () {
    $errors = array_map(fn (int $row) => new RowError($row, 'Mal'), range(1, 80));

    $load = bancosImport([], reading: new StatementReading([], $errors))->load->fresh();

    expect($load->error_count)->toBe(80)
        ->and($load->errors)->toHaveCount(50);
});

it('records a load even when the statement has no credits', function () {
    $result = bancosImport([bancosMovement('10.00', MovementType::Debit)]);

    expect(BankLoad::query()->count())->toBe(1)
        ->and($result->inserted())->toBe(0)
        ->and(BankMovement::query()->count())->toBe(0);
});

it('cuts texts that are longer than the columns', function () {
    bancosImport([bancosMovement(reference: str_repeat('R', 300), description: str_repeat('D', 900))]);

    $stored = BankMovement::query()->sole();

    expect(mb_strlen((string) $stored->reference))->toBe(100)
        ->and(mb_strlen((string) $stored->description))->toBe(500);
});

it('writes the audit trail', function () {
    $result = bancosImport([bancosMovement()]);

    $this->fakes->audit->assertRecorded('bancos.carga.imported', fn ($entry) => $entry->subjectId === $result->load->id
        && $entry->context['inserted'] === 1
        && $entry->context['bank'] === 'bogota');
});

describe('from a file', function () {
    it('imports the invented statement of each bank', function (Bank $bank, int $credits) {
        $result = app(StatementImport::class)->fromFile(Fixtures::path($bank), $bank, CompanyCode::SGX, ImportSource::Folder);

        expect($result->inserted())->toBe($credits)
            ->and($result->load->file_name)->toBe($bank->value.'-ejemplo-inventado.csv')
            ->and($result->load->file_sha256)->toBe(hash_file('sha256', Fixtures::path($bank)));
    })->with([
        'bogota' => [Bank::Bogota, 3],
        'davivienda' => [Bank::Davivienda, 2],
        'bancolombia' => [Bank::Bancolombia, 4],
        'miami' => [Bank::Miami, 2],
    ]);

    it('keeps the two identical Bancolombia transfers of the same day', function () {
        app(StatementImport::class)->fromFile(Fixtures::path(Bank::Bancolombia), Bank::Bancolombia, CompanyCode::SGX, ImportSource::Folder);

        expect(BankMovement::query()->where('amount', '500000.00')->count())->toBe(2);
    });

    it('loads the Miami account in dollars unless the configuration says otherwise', function () {
        app(StatementImport::class)->fromFile(Fixtures::path(Bank::Miami), Bank::Miami, CompanyCode::SGX, ImportSource::Folder);
        expect(BankMovement::query()->pluck('currency')->unique()->all())->toBe(['USD']);

        BankMovement::query()->delete();
        config()->set('bancos.currencies.miami', 'COP');

        app(StatementImport::class)->fromFile(Fixtures::path(Bank::Miami), Bank::Miami, CompanyCode::SDC, ImportSource::Folder);
        expect(BankMovement::query()->pluck('currency')->unique()->all())->toBe(['COP']);
    });

    it('refuses a statement of another bank without storing anything', function () {
        expect(fn () => app(StatementImport::class)->fromFile(Fixtures::path(Bank::Miami), Bank::Davivienda, CompanyCode::SGX, ImportSource::Folder))
            ->toThrow(UnrecognizedStatement::class);

        expect(BankLoad::query()->count())->toBe(0);
    });
});

describe('recording a failure', function () {
    it('keeps one entry for the same file however many times it fails', function () {
        $importer = app(MovementImporter::class);
        $sha = str_repeat('b', 64);

        $first = $importer->recordFailure(Bank::Bogota, CompanyCode::SGX, ImportSource::Folder, 'No es un extracto', 'malo.csv', $sha);
        $again = $importer->recordFailure(Bank::Bogota, CompanyCode::SGX, ImportSource::Folder, 'No es un extracto', 'malo.csv', $sha);

        expect($again->id)->toBe($first->id)
            ->and(BankLoad::query()->where('failed', true)->count())->toBe(1);
    });

    it('records the same file again for another bank or company', function () {
        $importer = app(MovementImporter::class);
        $sha = str_repeat('c', 64);

        $importer->recordFailure(Bank::Bogota, CompanyCode::SGX, ImportSource::Folder, 'x', 'a.csv', $sha);
        $importer->recordFailure(Bank::Davivienda, CompanyCode::SGX, ImportSource::Folder, 'x', 'a.csv', $sha);
        $importer->recordFailure(Bank::Bogota, CompanyCode::SDC, ImportSource::Folder, 'x', 'a.csv', $sha);

        expect(BankLoad::query()->where('failed', true)->count())->toBe(3);
    });
});
