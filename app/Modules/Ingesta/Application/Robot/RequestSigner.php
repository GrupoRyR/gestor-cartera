<?php

namespace App\Modules\Ingesta\Application\Robot;

/**
 * The HMAC signature of a robot request (wire protocol v1, shared with
 * etl/sinks/http_sink.py):
 *
 *     X-Ingesta-Signature: sha256=<lowercase hex HMAC-SHA256(secret, canonical)>
 *
 * where the canonical string joins with "\n", without a trailing newline,
 * the Unix timestamp, "POST", the path "/api/ingesta/{lote}" and the SHA-256
 * hex of the raw body exactly as sent (the gzip bytes when gzipped). The
 * path is built from {lote} on both sides, never from the request URI, so a
 * subfolder or a proxy does not break it.
 */
final class RequestSigner
{
    public const string PREFIX = 'sha256=';

    public static function canonical(int $timestamp, string $lote, string $body): string
    {
        return implode("\n", [$timestamp, 'POST', "/api/ingesta/{$lote}", hash('sha256', $body)]);
    }

    /**
     * The X-Ingesta-Signature header value.
     */
    public static function sign(string $secret, int $timestamp, string $lote, string $body): string
    {
        return self::PREFIX.hash_hmac('sha256', self::canonical($timestamp, $lote, $body), $secret);
    }

    /**
     * Compares in constant time with the expected header value.
     */
    public static function verify(string $secret, int $timestamp, string $lote, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $timestamp, $lote, $body), $signature);
    }
}
