<?php

namespace App\Modules\Ingesta\Application\Robot;

/**
 * The HMAC signature of a robot request (wire protocol v1, shared with
 * etl/sinks/http_sink.py):
 *
 *     X-Ingesta-Signature: sha256=<lowercase hex HMAC-SHA256(secret, canonical)>
 *
 * where the canonical string joins with "\n", without a trailing newline,
 * the Unix timestamp, "POST", the path of the route and the SHA-256 hex of
 * the raw body exactly as sent (the gzip bytes when gzipped). The path is
 * the route's own, like "/api/ingesta/cartera" or "/api/ingesta/reportes",
 * so a signature is good only on the route it was made for. The server
 * builds it from the matched route, never from the request URI, so a
 * subfolder or a proxy does not break it.
 */
final class RequestSigner
{
    public const string PREFIX = 'sha256=';

    /**
     * The signed path of a batch, as the robot builds it from {lote}.
     */
    public static function batchPath(string $lote): string
    {
        return "/api/ingesta/{$lote}";
    }

    public static function canonical(int $timestamp, string $path, string $body): string
    {
        return implode("\n", [$timestamp, 'POST', $path, hash('sha256', $body)]);
    }

    /**
     * The X-Ingesta-Signature header value.
     */
    public static function sign(string $secret, int $timestamp, string $path, string $body): string
    {
        return self::PREFIX.hash_hmac('sha256', self::canonical($timestamp, $path, $body), $secret);
    }

    /**
     * Compares in constant time with the expected header value.
     */
    public static function verify(string $secret, int $timestamp, string $path, string $body, string $signature): bool
    {
        return hash_equals(self::sign($secret, $timestamp, $path, $body), $signature);
    }
}
