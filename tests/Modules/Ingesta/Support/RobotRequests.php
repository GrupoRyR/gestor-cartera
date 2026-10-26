<?php

namespace Tests\Modules\Ingesta\Support;

use App\Modules\Ingesta\Application\Robot\RequestSigner;

/**
 * Builds robot requests the way etl/sinks/http_sink.py does: a columnar JSON
 * body, gzipped by default, signed with HMAC-SHA256.
 */
final class RobotRequests
{
    public const string KEY_ID = 'k2026a';

    public const string SECRET = 'k2026a-secreto-de-prueba-de-32-bytes';

    /**
     * The columnar body of a batch from associative rows.
     *
     * @param  list<array<string, scalar|null>>  $rows
     * @param  list<string>|null  $columns  Defaults to the keys of the first row.
     */
    public static function body(
        array $rows,
        string $batchId = 'asesores-SGX-2026-10-15-0123456789abcdef',
        string $company = 'SGX',
        string $cutoffDate = '2026-10-15',
        ?array $columns = null,
    ): string {
        $columns ??= array_keys($rows[0] ?? []);

        return json_encode([
            'batch_id' => $batchId,
            'company' => $company,
            'cutoff_date' => $cutoffDate,
            'columns' => $columns,
            'rows' => array_map(fn (array $row): array => array_values($row), $rows),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Server variables of a signed request; $signFor signs another lote
     * than the one requested.
     *
     * @return array<string, string>
     */
    public static function headers(
        string $lote,
        string $rawBody,
        bool $gzip = false,
        string $keyId = self::KEY_ID,
        string $secret = self::SECRET,
        ?int $timestamp = null,
        ?string $signFor = null,
    ): array {
        $timestamp ??= now()->getTimestamp();

        return array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CONTENT_ENCODING' => $gzip ? 'gzip' : '',
            'HTTP_X_INGESTA_KEY' => $keyId,
            'HTTP_X_INGESTA_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_INGESTA_SIGNATURE' => RequestSigner::sign($secret, $timestamp, $signFor ?? $lote, $rawBody),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * Gzips like the robot: mtime 0, so the same body always gives the same bytes.
     */
    public static function gzip(string $body): string
    {
        return (string) gzencode($body);
    }
}
