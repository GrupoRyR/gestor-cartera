<?php

use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Domain\Schema\Column;
use App\Modules\Ingesta\Domain\Schema\ColumnType;
use App\Modules\Ingesta\Domain\Schema\DatasetSchema;
use App\Modules\Ingesta\Domain\Schema\DatasetSchemas;

/*
|--------------------------------------------------------------------------
| Drift guard against etl/esquema.py
|--------------------------------------------------------------------------
|
| esquema.py is the source of truth of the robot contract. These tests read
| it with strict regular expressions: a line that looks like a Columna() or
| a Lote() but does not match the expected shape fails loudly, so a change
| in the Python file never slips through as "no columns found".
|
*/

/**
 * @return array<string, list<array{name: string, type: ColumnType, required: bool, sourceHeader: string}>>
 */
function esquemaColumnLists(string $source): array
{
    $types = [
        'TEXTO' => ColumnType::Text,
        'ENTERO' => ColumnType::Integer,
        'NUMERO' => ColumnType::Decimal2,
        'TASA' => ColumnType::Decimal6,
        'FECHA' => ColumnType::Date,
    ];

    preg_match_all('/^([A-Z_]+): list\[Columna\] = \[\n(.*?)^\]/msu', $source, $lists, PREG_SET_ORDER);

    if ($lists === []) {
        throw new RuntimeException('etl/esquema.py: no "NAME: list[Columna] = [...]" blocks found; update the drift test.');
    }

    $parsed = [];

    foreach ($lists as [, $name, $body]) {
        $parsed[$name] = [];

        foreach (explode("\n", $body) as $number => $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            $matched = preg_match(
                '/^\s*Columna\("([^"]+)",\s*"([a-z0-9_]+)",\s*(TEXTO|ENTERO|NUMERO|TASA|FECHA)(,\s*requerida=True)?\),\s*$/u',
                $line,
                $column,
            );

            if ($matched !== 1) {
                throw new RuntimeException("etl/esquema.py: {$name}, line {$number} of the list does not look like Columna(\"hana\", \"pg\", TIPO[, requerida=True]): {$line}");
            }

            $parsed[$name][] = [
                'name' => $column[2],
                'type' => $types[$column[3]],
                'required' => ($column[4] ?? '') !== '',
                'sourceHeader' => $column[1],
            ];
        }
    }

    return $parsed;
}

/**
 * @return array<string, array{list: string, key: list<string>, allowEmpty: bool}>
 */
function esquemaLotes(string $source): array
{
    if (preg_match('/^LOTES: dict\[str, Lote\] = \{\n(.*?)^\}/msu', $source, $block) !== 1) {
        throw new RuntimeException('etl/esquema.py: the "LOTES: dict[str, Lote] = {...}" block was not found; update the drift test.');
    }

    $pattern = '/Lote\(\s*"([a-z_]+)",\s*"[a-z_]+\.sql",\s*([A-Z_]+)'
        .'((?:\s*,\s*(?:llave=\((?:\s*"[a-z_]+"\s*,?)+\s*\)|permite_vacio=(?:True|False)))*)\s*,?\s*\)/su';

    preg_match_all($pattern, $block[1], $lotes, PREG_SET_ORDER);
    $declared = substr_count($block[1], 'Lote(');

    if (count($lotes) !== $declared) {
        throw new RuntimeException("etl/esquema.py: LOTES declares {$declared} Lote(...) but only ".count($lotes).' match the expected shape Lote("nombre", "consulta.sql", LISTA[, llave=(...)][, permite_vacio=True]).');
    }

    $parsed = [];

    foreach ($lotes as [, $name, $list, $options]) {
        preg_match('/llave=\(([^)]*)\)/', $options, $key);
        preg_match_all('/"([a-z_]+)"/', $key[1] ?? '', $keyNames);

        $parsed[$name] = [
            'list' => $list,
            'key' => $keyNames[1],
            'allowEmpty' => str_contains($options, 'permite_vacio=True'),
        ];
    }

    return $parsed;
}

/**
 * @return array{columns: list<array{name: string, type: ColumnType, required: bool, sourceHeader: string}>, key: list<string>, allowEmpty: bool}
 */
function describeSchema(DatasetSchema $schema): array
{
    return [
        'columns' => array_map(fn (Column $column): array => [
            'name' => $column->name,
            'type' => $column->type,
            'required' => $column->required,
            'sourceHeader' => $column->sourceHeader,
        ], $schema->columns),
        'key' => $schema->key,
        'allowEmpty' => $schema->allowEmpty,
    ];
}

it('mirrors every lote of etl/esquema.py exactly', function () {
    $source = file_get_contents(base_path('etl/esquema.py'));
    expect($source)->toBeString();

    // A Windows checkout may turn the file's line endings into CRLF.
    $source = str_replace("\r\n", "\n", (string) $source);
    $lists = esquemaColumnLists($source);
    $lotes = esquemaLotes($source);

    expect(array_keys($lotes))->toBe(array_map(fn (Dataset $dataset): string => $dataset->value, Dataset::cases()));

    foreach ($lotes as $name => $lote) {
        expect($lists)->toHaveKey($lote['list']);

        $schema = DatasetSchemas::for(Dataset::from($name));

        expect($schema->dataset)->toBe(Dataset::from($name))
            ->and(describeSchema($schema))->toBe([
                'columns' => $lists[$lote['list']],
                'key' => $lote['key'],
                'allowEmpty' => $lote['allowEmpty'],
            ], "The {$name} schema drifted from etl/esquema.py.");
    }
});

it('reads the cartera schema as the robot sends it', function () {
    $schema = DatasetSchemas::for(Dataset::Receivables);

    expect($schema->columnNames())->toHaveCount(38)
        ->and($schema->columnNames()[0])->toBe('slp_code')
        ->and($schema->column('saldo'))->toEqual(new Column('saldo', ColumnType::Decimal2, true, 'Saldo'))
        ->and($schema->column('no_existe'))->toBeNull()
        ->and($schema->key)->toBe([])
        ->and($schema->allowEmpty)->toBeFalse();
});

it('rejects a schema whose key is not one of its columns', function () {
    new DatasetSchema(Dataset::Advisors, [new Column('slp_code', ColumnType::Integer, true, 'SlpCode')], ['nombre']);
})->throws(InvalidArgumentException::class, 'The asesores key column [nombre] is not in the schema.');

it('rejects a schema that repeats a column', function () {
    $column = new Column('slp_code', ColumnType::Integer, true, 'SlpCode');

    new DatasetSchema(Dataset::Advisors, [$column, $column]);
})->throws(InvalidArgumentException::class, 'The asesores schema repeats a column.');
