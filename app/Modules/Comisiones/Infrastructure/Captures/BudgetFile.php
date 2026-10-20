<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Files\TabularFile;
use App\Modules\Comisiones\Infrastructure\Files\UnreadableFile;
use Brick\Math\BigDecimal;
use DateTimeInterface;

/**
 * Reads and checks a budget template (Parte B: «Período; Código de vendedor;
 * Presupuesto; Canal») for one period. Every row is checked before anything
 * is stored: one wrong row rejects the whole file, with a Spanish message
 * per wrong row.
 *
 * - The first non-empty row holds the headers, in any order; «Canal» may be
 *   missing. Accents and case do not matter.
 * - The period is "YYYY-MM" or "MM/AAAA" and must be the one being loaded.
 * - The code must have parameters in force in the period (CodesInForce)
 *   and appear once.
 * - The budget is a plain decimal or Colombian ("77.241.857",
 *   "$ 77.241.857"), above zero.
 */
final class BudgetFile
{
    private const array COLUMNS = [
        'periodo' => 'periodo',
        'codigo de vendedor' => 'codigo',
        'presupuesto' => 'presupuesto',
        'canal' => 'canal',
    ];

    /** Errors listed before the message says "and N more". */
    public const int MAX_ERRORS = 50;

    public function __construct(
        private readonly CodesInForce $codes,
    ) {}

    /**
     * @return array{lines: list<BudgetLine>, errors: list<string>}
     *
     * @throws UnreadableFile when the file cannot be opened.
     */
    public function read(string $path, string $extension, Period $period): array
    {
        $rows = TabularFile::rows($path, $extension);
        $headerAt = null;

        foreach ($rows as $index => $cells) {
            if (! self::blank($cells)) {
                $headerAt = $index;

                break;
            }
        }

        if ($headerAt === null) {
            return ['lines' => [], 'errors' => [__('comisiones::presupuesto.carga.vacio')]];
        }

        $columns = self::columns($rows[$headerAt]);

        if (! isset($columns['periodo'], $columns['codigo'], $columns['presupuesto'])) {
            return ['lines' => [], 'errors' => [__('comisiones::presupuesto.carga.encabezados', ['columnas' => implode('; ', BudgetSheet::HEADER)])]];
        }

        $known = [];

        foreach ($this->codes->of($period) as $rule) {
            $known[$rule->code] = true;
        }

        $lines = [];
        $errors = [];
        $seen = [];

        foreach (array_slice($rows, $headerAt + 1, preserve_keys: true) as $index => $cells) {
            if (self::blank($cells)) {
                continue;
            }

            $row = $index + 1;
            $rowErrors = [];
            $rowPeriod = self::period($cells[$columns['periodo']] ?? null);
            $code = self::code($cells[$columns['codigo']] ?? null);
            $amount = Amount::parse($cells[$columns['presupuesto']] ?? null);
            $channel = isset($columns['canal']) ? self::text($cells[$columns['canal']] ?? null) : null;

            if ($rowPeriod === null) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.periodo_invalido', ['valor' => self::shown($cells[$columns['periodo']] ?? null)]);
            } elseif ($rowPeriod !== $period->key()) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.otro_periodo', ['valor' => $rowPeriod, 'periodo' => $period->key()]);
            }

            if ($code === null) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.codigo_invalido', ['valor' => self::shown($cells[$columns['codigo']] ?? null)]);
            } elseif (! isset($known[$code])) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.codigo_desconocido', ['codigo' => $code, 'periodo' => $period->key()]);
            } elseif (isset($seen[$code])) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.codigo_repetido', ['codigo' => $code, 'fila' => $seen[$code]]);
            }

            if ($amount === null) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.valor_invalido', ['valor' => self::shown($cells[$columns['presupuesto']] ?? null)]);
            } elseif (! BigDecimal::of($amount)->isPositive()) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.valor_no_positivo');
            }

            if ($channel !== null && mb_strlen($channel) > 100) {
                $rowErrors[] = __('comisiones::presupuesto.carga.errores.canal_largo');
            }

            if ($code !== null) {
                $seen[$code] ??= $row;
            }

            if ($rowErrors !== []) {
                $errors[] = __('comisiones::presupuesto.carga.errores.fila', ['fila' => $row, 'errores' => implode(' ', $rowErrors)]);

                continue;
            }

            $lines[] = new BudgetLine($row, $code, $amount, $channel);
        }

        if ($lines === [] && $errors === []) {
            $errors[] = __('comisiones::presupuesto.carga.sin_filas');
        }

        return ['lines' => $errors === [] ? $lines : [], 'errors' => $errors];
    }

    /**
     * Column index of each known header.
     *
     * @param  list<mixed>  $cells
     * @return array<string, int>
     */
    private static function columns(array $cells): array
    {
        $columns = [];

        foreach ($cells as $index => $cell) {
            $name = self::header($cell);

            if (isset(self::COLUMNS[$name])) {
                $columns[self::COLUMNS[$name]] ??= $index;
            }
        }

        return $columns;
    }

    /** "Código de vendedor " → "codigo de vendedor". */
    private static function header(mixed $cell): string
    {
        $text = mb_strtolower(trim(is_scalar($cell) ? (string) $cell : ''));
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    /** "2026-07", "07/2026", "7/2026" or a date cell → "2026-07"; null when it is none. */
    private static function period(mixed $cell): ?string
    {
        if ($cell instanceof DateTimeInterface) {
            return $cell->format('Y-m');
        }

        $text = self::text($cell);

        if ($text === null) {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $text, $match) === 1) {
            [$year, $month] = [(int) $match[1], (int) $match[2]];
        } elseif (preg_match('/^(\d{1,2})\/(\d{4})$/', $text, $match) === 1) {
            [$month, $year] = [(int) $match[1], (int) $match[2]];
        } else {
            return null;
        }

        return $month >= 1 && $month <= 12 ? sprintf('%04d-%02d', $year, $month) : null;
    }

    private static function code(mixed $cell): ?int
    {
        if (is_int($cell)) {
            return $cell >= 1 ? $cell : null;
        }

        if (is_float($cell)) {
            return $cell >= 1 && floor($cell) === $cell ? (int) $cell : null;
        }

        $text = self::text($cell);

        return $text !== null && preg_match('/^\d{1,9}$/', $text) === 1 && (int) $text >= 1 ? (int) $text : null;
    }

    private static function text(mixed $cell): ?string
    {
        if (! is_scalar($cell)) {
            return null;
        }

        $text = trim((string) $cell);

        return $text === '' ? null : $text;
    }

    /** The cell as the user typed it, for an error message. */
    private static function shown(mixed $cell): string
    {
        return self::text($cell) ?? __('comisiones::presupuesto.carga.errores.vacia');
    }

    /**
     * @param  list<mixed>  $cells
     */
    private static function blank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell instanceof DateTimeInterface || (is_scalar($cell) && trim((string) $cell) !== '')) {
                return false;
            }
        }

        return true;
    }
}
