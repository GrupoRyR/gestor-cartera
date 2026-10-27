<?php

namespace App\Modules\Bancos\Http\Middleware;

use App\Modules\Core\Contracts\AuditLogger;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Treasury API with a bearer token from the configuration. The
 * route says which token with the middleware parameter:
 *
 *     VerifyTreasuryToken::class.':api_token'      GET /api/recaudo (read)
 *     VerifyTreasuryToken::class.':ingest_token'   POST /api/movimientos (write)
 *
 * Two tokens, so a leaked read token cannot write. With no token configured,
 * or one shorter than MIN_TOKEN_BYTES, the API is closed: every request is
 * refused, never let through. Every refusal is audited as bancos.api.refused
 * with the setting and the reason, never the token; the route's throttle
 * (bancos-api, 60 per minute per IP) runs first and bounds how many there are.
 */
final class VerifyTreasuryToken
{
    /** Shortest token accepted, in bytes (strlen); a shorter one closes the API. */
    public const int MIN_TOKEN_BYTES = 32;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  string  $setting  Key under "bancos" in the configuration that holds the token.
     */
    public function handle(Request $request, Closure $next, string $setting): Response
    {
        $expected = config('bancos.'.$setting);
        $given = $request->bearerToken();

        if (! is_string($expected) || $expected === '') {
            Log::warning('Bancos API refused a request: no token is configured.', ['setting' => $setting]);

            return $this->refuse($request, $setting, 'not_configured');
        }

        if (strlen($expected) < self::MIN_TOKEN_BYTES) {
            Log::warning('Bancos API refused a request: the configured token is shorter than '.self::MIN_TOKEN_BYTES.' bytes.', ['setting' => $setting]);

            return $this->refuse($request, $setting, 'too_short');
        }

        if (! is_string($given) || $given === '') {
            return $this->refuse($request, $setting, 'missing');
        }

        // Hashed first so the comparison takes the same time whatever the length.
        if (! hash_equals(hash('sha256', $expected), hash('sha256', $given))) {
            return $this->refuse($request, $setting, 'mismatch');
        }

        return $next($request);
    }

    /**
     * @param  'not_configured'|'too_short'|'missing'|'mismatch'  $reason
     */
    private function refuse(Request $request, string $setting, string $reason): JsonResponse
    {
        // The logger adds the IP, the user agent and the time.
        $this->audit->record('bancos.api.refused', context: [
            'setting' => $setting,
            'reason' => $reason,
            'path' => $request->path(),
        ]);

        return response()->json(['message' => 'No autorizado.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
