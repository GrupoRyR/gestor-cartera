<?php

namespace Tests\Modules\Asesores\Support;

use Illuminate\Testing\TestResponse;
use Tests\Feature\Design\Support\Html;

/**
 * Readers of the rendered Asesores pages, so the tests state what a person
 * sees (a figure, a column of names) instead of repeating XPath.
 */
final class Page
{
    public static function html(TestResponse $response): Html
    {
        return Html::parse($response->getContent() ?: '');
    }

    /**
     * The value shown on the tile labelled $label, e.g. "$ 8.500.000".
     */
    public static function stat(TestResponse $response, string $label): string
    {
        return self::html($response)->text("//*[@data-part='label'][normalize-space(.)='{$label}']/following-sibling::*[@data-part='value']");
    }

    /**
     * The client names of the first table, in page order.
     *
     * @return list<string>
     */
    public static function clientNames(TestResponse $response): array
    {
        return array_map(
            fn ($cell): string => Html::clean($cell->textContent),
            self::html($response)->all('//table/tbody/tr[not(@data-part="empty")]/td[1]/*[1]'),
        );
    }

    /**
     * The text of one column of the first table, in page order.
     *
     * @return list<string>
     */
    public static function column(TestResponse $response, int $position): array
    {
        return array_map(
            fn ($cell): string => Html::clean($cell->textContent),
            self::html($response)->all("//table/tbody/tr[not(@data-part=\"empty\")]/td[{$position}]"),
        );
    }

    /**
     * The text of one column of the table with the given caption, in page
     * order. For pages with more than one table.
     *
     * @return list<string>
     */
    public static function tableColumn(TestResponse $response, string $caption, int $position): array
    {
        return array_map(
            fn ($cell): string => Html::clean($cell->textContent),
            self::html($response)->all("//table[caption[normalize-space(.)='{$caption}']]/tbody/tr[not(@data-part=\"empty\")]/td[{$position}]"),
        );
    }

    /**
     * The client names listed under the heading of a card.
     *
     * @return list<string>
     */
    public static function listedUnder(TestResponse $response, string $heading): array
    {
        return array_map(
            fn ($item): string => Html::clean($item->textContent),
            self::html($response)->all("//section[.//h2[normalize-space(.)='{$heading}']]//li/span[1]/*[1]"),
        );
    }
}
