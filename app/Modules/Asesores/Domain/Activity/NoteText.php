<?php

namespace App\Modules\Asesores\Domain\Activity;

/**
 * Splits what an advisor typed in the note box into what the client log
 * stores: a one-line summary, and the whole text as the details when the
 * summary does not hold it all.
 */
final class NoteText
{
    /**
     * Longest summary, in characters: the log shows it as one line.
     */
    public const int SUMMARY_LENGTH = 150;

    public static function split(string $text): Note
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return new Note('', null);
        }

        $first = trim(explode("\n", $text)[0]);
        $summary = mb_strlen($first) > self::SUMMARY_LENGTH
            ? rtrim(mb_substr($first, 0, self::SUMMARY_LENGTH - 1)).'…'
            : $first;

        return new Note($summary, $summary === $text ? null : $text);
    }
}
