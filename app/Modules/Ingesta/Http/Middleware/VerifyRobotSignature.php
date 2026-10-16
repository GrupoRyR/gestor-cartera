<?php

namespace App\Modules\Ingesta\Http\Middleware;

use App\Modules\Ingesta\Application\Robot\RequestSigner;
use App\Modules\Ingesta\Infrastructure\Robot\HmacKeyring;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets through only requests signed by the robot (RequestSigner): a known
 * X-Ingesta-Key, an X-Ingesta-Timestamp within config('ingesta.hmac_max_skew')
 * seconds of now and a matching X-Ingesta-Signature over the raw body. It
 * runs before the body is decompressed or parsed.
 *
 * Every failure answers the same 401, so a caller learns nothing about
 * which check failed; the reason, the key id and the IP go to the log,
 * never a secret.
 */
final class VerifyRobotSignature
{
    private const string TIMESTAMP = '/^\d{1,12}$/';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $keyring = HmacKeyring::parse((string) config('ingesta.hmac_keys'));
        $keyId = (string) $request->header('X-Ingesta-Key', '');

        if ($keyring->isEmpty()) {
            Log::error('Ingesta: refused a robot request because INGESTA_HMAC_KEYS has no valid key.', [
                'ip' => $request->ip(),
            ]);

            return $this->refuse();
        }

        $reason = $this->failure($request, $keyring, $keyId);

        if ($reason !== null) {
            Log::warning('Ingesta: refused a robot request with an invalid signature.', [
                'reason' => $reason,
                // An id the keyring could never hold is not echoed (log injection).
                'key' => HmacKeyring::isWellFormedId($keyId) ? $keyId : null,
                'ip' => $request->ip(),
            ]);

            return $this->refuse();
        }

        return $next($request);
    }

    /**
     * Why the request is not validly signed; null when it is.
     */
    private function failure(Request $request, HmacKeyring $keyring, string $keyId): ?string
    {
        $timestamp = (string) $request->header('X-Ingesta-Timestamp', '');
        $signature = (string) $request->header('X-Ingesta-Signature', '');

        if ($keyId === '' || $timestamp === '' || $signature === '') {
            return 'missing_headers';
        }

        $secret = $keyring->secret($keyId);

        if ($secret === null) {
            return 'unknown_key';
        }

        if (preg_match(self::TIMESTAMP, $timestamp) !== 1) {
            return 'malformed_timestamp';
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > (int) config('ingesta.hmac_max_skew')) {
            return 'timestamp_out_of_skew';
        }

        $lote = (string) $request->route('lote');

        if (! RequestSigner::verify($secret, (int) $timestamp, $lote, $request->getContent(), $signature)) {
            return 'signature_mismatch';
        }

        return null;
    }

    private function refuse(): JsonResponse
    {
        return new JsonResponse(['message' => __('ingesta::robot.firma_invalida')], Response::HTTP_UNAUTHORIZED);
    }
}
