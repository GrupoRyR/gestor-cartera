<?php

namespace App\Modules\Cobro\Infrastructure\Export;

use App\Modules\Cobro\Providers\CobroServiceProvider;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;

/**
 * Builds the cells of the Cobro Excel exports.
 *
 * Text is always a StringCell. OpenSpout's own Cell::fromValue() would turn
 * any string that starts with "=" into a formula, and client names and
 * reasons come from SAP or from people: a cell such as =HYPERLINK(...) must
 * reach Excel as text, never run. Dates are written in Bogotá time.
 */
final class Cells
{
    private const string MONEY_FORMAT = '$ #,##0;-$ #,##0';

    private const string DECIMAL_FORMAT = '0.0';

    private const string DATE_FORMAT = 'dd/mm/yyyy';

    private const string DATE_TIME_FORMAT = 'dd/mm/yyyy hh:mm';

    /**
     * A header row: the labels in bold.
     *
     * @param  list<string>  $labels
     */
    public static function header(array $labels): Row
    {
        $style = (new Style)->withFontBold(true)->withShouldWrapText(true);

        return new Row(array_map(fn (string $label): Cell => new StringCell($label, $style), $labels));
    }

    public static function text(?string $value): Cell
    {
        return $value === null || $value === '' ? new EmptyCell(null) : new StringCell($value, null);
    }

    /**
     * Pesos as a number, shown as "$ 1,234,567" (or with the user's separators
     * in Excel), so the column can be added up.
     */
    public static function money(?float $value): Cell
    {
        return $value === null ? new EmptyCell(null) : new NumericCell($value, (new Style)->withFormat(self::MONEY_FORMAT));
    }

    public static function integer(?int $value): Cell
    {
        return $value === null ? new EmptyCell(null) : new NumericCell($value);
    }

    /**
     * A number shown with one decimal, such as a duration in hours.
     */
    public static function decimal(?float $value): Cell
    {
        return $value === null ? new EmptyCell(null) : new NumericCell($value, (new Style)->withFormat(self::DECIMAL_FORMAT));
    }

    /**
     * The Bogotá calendar day: the time of day is dropped, so the cell
     * filters and sorts by day.
     */
    public static function date(?DateTimeInterface $value): Cell
    {
        if ($value === null) {
            return new EmptyCell(null);
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', self::inBogota($value)->format('Y-m-d'));

        return $day === false ? new EmptyCell(null) : new DateTimeCell($day, (new Style)->withFormat(self::DATE_FORMAT));
    }

    public static function dateTime(?DateTimeInterface $value): Cell
    {
        return $value === null ? new EmptyCell(null) : new DateTimeCell(self::inBogota($value), (new Style)->withFormat(self::DATE_TIME_FORMAT));
    }

    /**
     * @param  list<Cell>  $cells
     */
    public static function row(array $cells): Row
    {
        return new Row($cells);
    }

    private static function inBogota(DateTimeInterface $value): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone(CobroServiceProvider::TIMEZONE));
    }
}
