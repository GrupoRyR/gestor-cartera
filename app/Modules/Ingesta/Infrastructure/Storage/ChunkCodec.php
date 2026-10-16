<?php

namespace App\Modules\Ingesta\Infrastructure\Storage;

use JsonException;
use UnexpectedValueException;

/**
 * Encodes the rows of an accepted load for ingesta_load_chunks: a JSON list
 * of value lists (no keys, the column order lives on the load), gzipped and
 * base64-encoded so it fits a text column on MySQL and SQLite alike. JSON
 * keeps the exact types of the values (int, decimal string, null).
 */
final class ChunkCodec
{
    /** Rows per chunk: a few hundred kilobytes of JSON for the widest dataset. */
    public const int ROWS = 500;

    /**
     * @param  list<list<scalar|null>>  $rows
     */
    public static function encode(array $rows): string
    {
        $json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return base64_encode((string) gzencode($json));
    }

    /**
     * @return list<list<scalar|null>>
     *
     * @throws UnexpectedValueException When the payload is not a chunk this codec wrote.
     */
    public static function decode(string $payload): array
    {
        $gzip = base64_decode($payload, strict: true);
        $json = $gzip === false ? false : @gzdecode($gzip);

        if ($json === false) {
            throw new UnexpectedValueException('The load chunk is not valid base64 gzip data.');
        }

        try {
            $rows = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnexpectedValueException('The load chunk is not valid JSON.', 0, $e);
        }

        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new UnexpectedValueException('The load chunk is not a list of rows.');
        }

        foreach ($rows as $row) {
            if (! is_array($row) || ! array_is_list($row)) {
                throw new UnexpectedValueException('A row of the load chunk is not a list of values.');
            }

            foreach ($row as $value) {
                if ($value !== null && ! is_scalar($value)) {
                    throw new UnexpectedValueException('A value of the load chunk is not a scalar.');
                }
            }
        }

        /** @var list<list<scalar|null>> $rows */
        return $rows;
    }
}
