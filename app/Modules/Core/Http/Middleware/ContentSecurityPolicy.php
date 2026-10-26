<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the Content-Security-Policy of every response of the app (audit
 * S-06). CoreServiceProvider pushes it as global middleware, so redirects,
 * error pages and the API get it too. public/.htaccess sends the other
 * security headers; this one needs a nonce per request, so the app sends it.
 *
 * Each request gets a new nonce: Vite puts it on its script tags, and an
 * inline <script> must carry it with nonce="{{ Vite::cspNonce() }}".
 *
 * - script-src keeps 'unsafe-eval' because the standard Alpine build
 *   evaluates its x-data and x-on expressions; dropping it needs the CSP
 *   build of Alpine and every component moved to Alpine.data().
 * - style-src keeps 'unsafe-inline' without a nonce: views use style=""
 *   attributes and the layout prints the brand variables in a <style>, and a
 *   nonce would make the browser ignore 'unsafe-inline'.
 *
 * In local there is no policy: the Vite dev server serves the scripts from
 * another origin.
 */
final class ContentSecurityPolicy
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->app->isLocal()) {
            return $next($request);
        }

        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "manifest-src 'self'",
            "worker-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]));

        return $response;
    }
}
