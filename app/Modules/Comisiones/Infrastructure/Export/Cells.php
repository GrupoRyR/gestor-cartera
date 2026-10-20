<?php

namespace App\Modules\Comisiones\Infrastructure\Export;

use App\Modules\Comisiones\Domain\Shared\Money;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Builds the cells of the Comisiones workbooks.
 *
 * Text is always a StringCell: OpenSpout's Cell::fromValue() would turn a
 * string that starts with "=" into a formula, and names come from SAP or
 * from people. Amounts are numbers shown as "$ 1.234.567", so the payroll
 * analyst can add them up.
 */
final class Cells
{
    private const string MONEY_FORMAT = '$ #,##0;-$ #,##0';

    /**
     * A row of labels in bold, such as a title or a column header.
     *
     * @param  list<string>  $labels
     */
    public static function bold(array $labels): Row
    {
        $style = (new Style)->withFontBold(true);

        return new Row(array_map(fn (string $label): Cell => new StringCell($label, $style), $labels));
    }

    public static function text(?string $value, bool $bold = false): Cell
    {
        if ($value === null || $value === '') {
            return new EmptyCell(null);
        }

        return new StringCell($value, $bold ? (new Style)->withFontBold(true) : null);
    }

    /** Pesos as a number with the peso format. */
    public static function money(?Money $value, bool $bold = false): Cell
    {
        if ($value === null) {
            return new EmptyCell(null);
        }

        $style = (new Style)->withFormat(self::MONEY_FORMAT);

        return new NumericCell((float) $value->toCents()->amount(), $bold ? $style->withFontBold(true) : $style);
    }

    public static function empty(): Cell
    {
        return new EmptyCell(null);
    }

    /**
     * @param  list<Cell>  $cells
     */
    public static function row(array $cells): Row
    {
        return new Row($cells);
    }

    public static function blank(): Row
    {
        return new Row([]);
    }
}
