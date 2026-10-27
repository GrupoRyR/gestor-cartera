<?php

namespace App\Modules\Cobro\Domain\Templates;

/**
 * Line-level differences between two template texts (already normalized
 * to "\n"), for the template change notice: the longest common
 * subsequence of their lines, every other old line removed and every
 * other new line added, in the order of both texts. A replaced line shows
 * as removed, then added.
 */
final class TextDiff
{
    public const string SAME = 'same';

    public const string REMOVED = 'removed';

    public const string ADDED = 'added';

    /**
     * Above this many cells the LCS table would be too large: the middle of
     * both texts then shows as all removed, then all added. The editor's
     * 5000-character limit keeps real templates far below it.
     */
    private const int MAX_CELLS = 250_000;

    /**
     * @return list<array{type: self::SAME|self::REMOVED|self::ADDED, line: string}>
     */
    public static function lines(string $old, string $new): array
    {
        $before = $old === '' ? [] : explode("\n", $old);
        $after = $new === '' ? [] : explode("\n", $new);

        // The common start and end need no table.
        $start = 0;
        while ($start < count($before) && $start < count($after) && $before[$start] === $after[$start]) {
            $start++;
        }

        $end = 0;
        while ($end < count($before) - $start && $end < count($after) - $start
            && $before[count($before) - 1 - $end] === $after[count($after) - 1 - $end]) {
            $end++;
        }

        $diff = array_map(fn (string $line): array => ['type' => self::SAME, 'line' => $line], array_slice($before, 0, $start));
        $middle = self::middle(
            array_slice($before, $start, count($before) - $start - $end),
            array_slice($after, $start, count($after) - $start - $end),
        );

        foreach (array_slice($before, count($before) - $end) as $line) {
            $middle[] = ['type' => self::SAME, 'line' => $line];
        }

        return [...$diff, ...$middle];
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<array{type: self::SAME|self::REMOVED|self::ADDED, line: string}>
     */
    private static function middle(array $before, array $after): array
    {
        $rows = count($before);
        $columns = count($after);

        if ($rows * $columns > self::MAX_CELLS) {
            return [
                ...array_map(fn (string $line): array => ['type' => self::REMOVED, 'line' => $line], $before),
                ...array_map(fn (string $line): array => ['type' => self::ADDED, 'line' => $line], $after),
            ];
        }

        // $length[$i * $width + $j]: the LCS length of $before from $i and
        // $after from $j, filled from the end.
        $width = $columns + 1;
        $length = array_fill(0, ($rows + 1) * $width, 0);

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $columns - 1; $j >= 0; $j--) {
                $length[$i * $width + $j] = $before[$i] === $after[$j]
                    ? $length[($i + 1) * $width + $j + 1] + 1
                    : max($length[($i + 1) * $width + $j], $length[$i * $width + $j + 1]);
            }
        }

        $diff = [];
        $i = 0;
        $j = 0;

        while ($i < $rows || $j < $columns) {
            if ($i < $rows && $j < $columns && $before[$i] === $after[$j]) {
                $diff[] = ['type' => self::SAME, 'line' => $before[$i++]];
                $j++;
            } elseif ($j >= $columns || ($i < $rows && $length[($i + 1) * $width + $j] >= $length[$i * $width + $j + 1])) {
                // On a tie the removal goes first.
                $diff[] = ['type' => self::REMOVED, 'line' => $before[$i++]];
            } else {
                $diff[] = ['type' => self::ADDED, 'line' => $after[$j++]];
            }
        }

        return $diff;
    }
}
