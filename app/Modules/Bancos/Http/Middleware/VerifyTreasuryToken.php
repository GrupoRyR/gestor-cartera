<?php

namespace App\Modules\Bancos\Http\Middleware;

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
 * Two tokens, so a leaked read token cannot write. With no token configured
 * the API is closed: every request is refused, never let through.
 */
final class VerifyTreasuryToken
{
    /**
     * @param  string  $setting  Key under "bancos" in the configuration that holds the token.
     */
    public function handle(Request $request, Closure $next, string $setting): Response
    {
        $expected = config('bancos.'.$setting);
        $given = $request->bearerToken();

        if (! is_string($expected) || $expected === '') {
            Log::warning('Bancos API refused a request: no token is configured.', ['setting' => $setting]);

            return $this->refuse();
        }

        // Hashed first so the comparison takes the same time whatever the length.
        if (! is_string($given) || ! hash_equals(hash('sha256', $expected), hash('sha256', $given))) {
            return $this->refuse();
        }

        return $next($request);
    }

    private function refuse(): JsonResponse
    {
        return response()->json(['message' => 'No autorizado.'], 401, ['WWW-Authenticate' => 'Bearer']);
    }
}
