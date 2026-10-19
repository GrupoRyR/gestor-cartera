<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Movement\MovementType;
use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Bancos\Domain\Statement\NumberStyle;
use App\Modules\Bancos\Domain\Statement\StatementMovement;
use App\Modules\Bancos\Domain\Statement\StatementReading;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * Movements pushed by Treasury's app. They arrive already in the normalized
 * format the file readers produce (date, direction, positive amount, texts and
 * currency), so from here on they take the same road as a file: the importer
 * keeps the credits and skips the ones it already has.
 */
final class ReceiveMovements
{
    public function __construct(
        private readonly MovementImporter $importer,
        private readonly AccountReaders $readers,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  Validated by ReceiveMovementsRequest.
     * @param  string|null  $label  Name of the batch to show in the history, e.g. the statement it came from.
     */
    public function handle(Bank $bank, CompanyCode $company, array $rows, ?string $label = null): ImportResult
    {
        $currency = $this->readers->for($bank)->currency();

        $movements = array_map(fn (array $row): StatementMovement => new StatementMovement(
            date: Cells::date((string) $row['fecha'], ['Y-m-d']) ?? throw new \UnexpectedValueException('Invalid date after validation.'),
            type: ($row['tipo'] ?? null) === MovementType::Debit->value ? MovementType::Debit : MovementType::Credit,
            amount: ltrim((string) Cells::amount($row['valor'], NumberStyle::International), '-'),
            description: self::text($row['descripcion'] ?? null),
            reference: self::text($row['referencia'] ?? null),
            account: self::text($row['cuenta'] ?? null),
            currency: is_string($row['moneda'] ?? null) && $row['moneda'] !== '' ? strtoupper($row['moneda']) : $currency,
        ), $rows);

        return $this->importer->import($bank, $company, new StatementReading($movements), ImportSource::Api, $label);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
