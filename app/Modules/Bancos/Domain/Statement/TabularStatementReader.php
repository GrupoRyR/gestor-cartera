<?php

namespace App\Modules\Bancos\Domain\Statement;

use App\Modules\Bancos\Domain\Movement\MovementType;
use App\Modules\Core\Contracts\Enums\Bank;

/**
 * The reading logic every bank shares: find the header row, map its columns
 * by name (so a bank that adds or moves a column does not break the import),
 * and turn each data row into credits and debits.
 *
 * A bank whose file has no header row (Bancolombia) gives the position of
 * each column in its profile instead: every non-empty row is then data.
 *
 * Each bank extends it with only its BankProfile. A quirk that a real extract
 * shows later is fixed by overriding in that bank's class, not here.
 *
 * Rows are classified so nothing disappears silently:
 *  - an empty row is skipped; a row without a date (a total, a note) is
 *    counted as ignored;
 *  - in a file without a header, a row with fewer cells than the columns
 *    need is an error with its row number;
 *  - a row with a date but an unreadable one, or an unreadable amount, is an
 *    error with its row number;
 *  - a row with a zero amount is ignored.
 */
abstract class TabularStatementReader implements StatementReader
{
    /** Rows searched for the header row: banks print a title and the account above it. */
    public const int HEADER_SEARCH_ROWS = 40;

    /** Longest description a movement carries: the size of the column that stores it. */
    public const int MAX_DESCRIPTION = 500;

    private const string DETAIL_SEPARATOR = ' · ';

    /**
     * @param  string|null  $currency  ISO code of the account when it is not the one the profile assumes.
     */
    public function __construct(private readonly ?string $currency = null) {}

    /**
     * The headers, formats and number style of this bank.
     */
    abstract protected function bankProfile(): BankProfile;

    final protected function profile(): BankProfile
    {
        $profile = $this->bankProfile();

        return $this->currency === null ? $profile : $profile->withCurrency($this->currency);
    }

    public function currency(): string
    {
        return $this->profile()->currency;
    }

    public function bank(): Bank
    {
        return $this->profile()->bank;
    }

    public function read(array $rows): StatementReading
    {
        $profile = $this->profile();
        [$firstDataRow, $columns] = $profile->isPositional()
            ? $this->checkPositions($rows, $profile)
            : $this->locateHeader($rows, $profile);

        $movements = [];
        $errors = [];
        $ignored = 0;

        foreach (array_slice($rows, $firstDataRow, null, true) as $index => $row) {
            $number = $index + 1;

            if ($this->isEmpty($row)) {
                continue;
            }

            // Without a header every row is a movement: one cut short is an error, not a note.
            if ($profile->isPositional() && count($row) < $profile->requiredCells()) {
                $errors[] = new RowError($number, sprintf(
                    'La fila tiene %d columnas y el extracto de %s tiene al menos %d.',
                    count($row),
                    $profile->bank->label(),
                    $profile->requiredCells(),
                ));

                continue;
            }

            $date = Cells::date($this->cell($row, $columns, Column::Date), $profile->dateFormats);

            if ($date === null) {
                $text = Cells::text($this->cell($row, $columns, Column::Date));

                // "Total" or "Saldo anterior" are notes; "31/02/2026" is a date that went wrong.
                if ($text !== null && preg_match('/\d/', $text) === 1 && $this->hasAmount($row, $columns, $profile)) {
                    $errors[] = new RowError($number, 'La fecha «'.$text.'» no es válida.');
                } else {
                    $ignored++;
                }

                continue;
            }

            try {
                $found = $this->amounts($row, $columns, $profile);
            } catch (UnreadableCell $e) {
                $errors[] = new RowError($number, $e->getMessage());

                continue;
            }

            if ($found === []) {
                $ignored++;

                continue;
            }

            foreach ($found as [$type, $amount]) {
                $movements[] = new StatementMovement(
                    date: $date,
                    type: $type,
                    amount: $amount,
                    description: $this->description($row, $columns),
                    reference: Cells::text($this->cell($row, $columns, Column::Reference)),
                    account: Cells::text($this->cell($row, $columns, Column::Account)),
                    currency: $this->rowCurrency($row, $columns, $profile),
                );
            }
        }

        return new StatementReading($movements, $errors, $ignored);
    }

    /**
     * The first row that names a date column and an amount (a Credit column or
     * an Amount column), and the position of every column it names.
     *
     * @param  list<list<mixed>>  $rows
     * @return array{int, array<string, int>} The index of the first row after the header, and the columns.
     *
     * @throws UnrecognizedStatement
     */
    private function locateHeader(array $rows, BankProfile $profile): array
    {
        foreach (array_slice($rows, 0, self::HEADER_SEARCH_ROWS, true) as $index => $row) {
            $columns = [];

            foreach ($row as $position => $cell) {
                $role = $profile->roleOf(Cells::header($cell));

                if ($role !== null && ! isset($columns[$role->value])) {
                    $columns[$role->value] = $position;
                }
            }

            if (isset($columns[Column::Date->value]) && (isset($columns[Column::Credit->value]) || isset($columns[Column::Amount->value]))) {
                return [$index + 1, $columns];
            }
        }

        throw new UnrecognizedStatement(sprintf(
            'No encontré las columnas de %s (fecha y valor o crédito) en las primeras %d filas. Revisa que el archivo sea el extracto de ese banco y que no esté vacío.',
            $profile->bank->label(),
            self::HEADER_SEARCH_ROWS,
        ));
    }

    /**
     * For a file without a header: makes sure it is this bank's, by finding,
     * among its first non-empty rows, one wide enough for every column and
     * with a date where the date goes. Every row is data.
     *
     * @param  list<list<mixed>>  $rows
     * @return array{int, array<string, int>} The index of the first data row (0), and the columns.
     *
     * @throws UnrecognizedStatement
     */
    private function checkPositions(array $rows, BankProfile $profile): array
    {
        $date = $profile->positions[Column::Date->value] ?? null;
        $looked = 0;

        foreach ($rows as $row) {
            if ($this->isEmpty($row)) {
                continue;
            }

            if ($date !== null && count($row) >= $profile->requiredCells() && Cells::date($row[$date] ?? null, $profile->dateFormats) !== null) {
                return [0, $profile->positions];
            }

            if (++$looked >= self::HEADER_SEARCH_ROWS) {
                break;
            }
        }

        throw new UnrecognizedStatement(sprintf(
            'No reconocí el extracto de %s: espero filas sin encabezado, de al menos %d columnas, con la fecha como %s en la columna %d. Revisa que el archivo sea el extracto de ese banco y que no esté vacío.',
            $profile->bank->label(),
            $profile->requiredCells(),
            implode(' o ', array_map(fn (string $format): string => strtr($format, ['d' => 'dd', 'm' => 'mm', 'Y' => 'aaaa']), $profile->dateFormats)),
            ($date ?? 0) + 1,
        ));
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     * @return list<array{MovementType, string}> Positive amounts; empty when the row has no money.
     *
     * @throws UnreadableCell
     */
    private function amounts(array $row, array $columns, BankProfile $profile): array
    {
        $found = [];

        if (isset($columns[Column::Credit->value]) || isset($columns[Column::Debit->value])) {
            foreach ([[Column::Credit, MovementType::Credit], [Column::Debit, MovementType::Debit]] as [$column, $type]) {
                $amount = Cells::amount($this->cell($row, $columns, $column), $profile->numbers);

                if ($amount !== null && ltrim($amount, '-') !== '0.00') {
                    $found[] = [$type, ltrim($amount, '-')];
                }
            }

            return $found;
        }

        $amount = Cells::amount($this->cell($row, $columns, Column::Amount), $profile->numbers);

        if ($amount === null || ltrim($amount, '-') === '0.00') {
            return [];
        }

        $type = isset($columns[Column::Type->value])
            ? $this->typeOf($this->cell($row, $columns, Column::Type))
            : ($amount[0] === '-' ? MovementType::Debit : MovementType::Credit);

        return [[$type, ltrim($amount, '-')]];
    }

    /**
     * @throws UnreadableCell
     */
    private function typeOf(mixed $cell): MovementType
    {
        $word = Cells::header($cell);

        return match (true) {
            in_array($word, BankProfile::CREDIT_WORDS, true) => MovementType::Credit,
            in_array($word, BankProfile::DEBIT_WORDS, true) => MovementType::Debit,
            default => throw new UnreadableCell('No sé si «'.Cells::text($cell).'» es un crédito o un débito.'),
        };
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function hasAmount(array $row, array $columns, BankProfile $profile): bool
    {
        try {
            return $this->amounts($row, $columns, $profile) !== [];
        } catch (UnreadableCell) {
            return true;
        }
    }

    /**
     * A free text of the statement as it is stored: whitespace collapsed. A
     * bank whose texts carry noise (labels, glued words) overrides it.
     */
    protected function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The Description and Detail texts, cleaned and joined, cut to the length
     * the movement table keeps.
     *
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function description(array $row, array $columns): ?string
    {
        $parts = [];

        foreach ([Column::Description, Column::Detail] as $column) {
            $text = $this->cleanText((string) Cells::text($this->cell($row, $columns, $column)));

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return $parts === [] ? null : mb_substr(implode(self::DETAIL_SEPARATOR, $parts), 0, self::MAX_DESCRIPTION);
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function rowCurrency(array $row, array $columns, BankProfile $profile): string
    {
        $code = strtoupper((string) Cells::text($this->cell($row, $columns, Column::Currency)));

        return preg_match('/^[A-Z]{3}$/', $code) === 1 ? $code : $profile->currency;
    }

    /**
     * @param  list<mixed>  $row
     * @param  array<string, int>  $columns
     */
    private function cell(array $row, array $columns, Column $column): mixed
    {
        $position = $columns[$column->value] ?? null;

        return $position === null ? null : ($row[$position] ?? null);
    }

    /**
     * @param  list<mixed>  $row
     */
    private function isEmpty(array $row): bool
    {
        foreach ($row as $cell) {
            if (Cells::text($cell) !== null || $cell instanceof \DateTimeInterface) {
                return false;
            }
        }

        return true;
    }
}
