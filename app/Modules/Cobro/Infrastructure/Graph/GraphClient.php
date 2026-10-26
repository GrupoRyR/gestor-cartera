<?php

namespace App\Modules\Cobro\Infrastructure\Graph;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Thin Microsoft Graph v1.0 client over Laravel's HTTP client, with an
 * app-only token (client credentials of config cobro.graph) cached,
 * encrypted with the APP_KEY, until a few minutes before it expires. No
 * SDK: tests fake every call with Http::fake() and recorded responses.
 *
 * Every failure (not configured, no connection, a non-2xx answer) becomes a
 * GraphRequestFailed without secrets. A 401 also drops the cached token so
 * the next call asks for a new one. An absolute URL (a next or delta link)
 * is followed only under BASE_URL; any other one is refused before the
 * token is read, so the token never goes to another host.
 */
final class GraphClient
{
    public const string BASE_URL = 'https://graph.microsoft.com/v1.0';

    public const string SCOPE = 'https://graph.microsoft.com/.default';

    /**
     * $graphCode of the GraphRequestFailed of a refused absolute URL.
     */
    public const string REJECTED_URL = 'rejected_url';

    private const string HOST = 'graph.microsoft.com';

    private const string TOKEN_CACHE_KEY = 'cobro.graph.token';

    /**
     * Whether the tenant, client id and secret of config cobro.graph are set.
     */
    public static function configured(): bool
    {
        return trim((string) config('cobro.graph.tenant_id')) !== ''
            && trim((string) config('cobro.graph.client_id')) !== ''
            && trim((string) config('cobro.graph.client_secret')) !== '';
    }

    /**
     * Graph path of a mailbox: "users/<address>".
     */
    public static function mailbox(string $address): string
    {
        return 'users/'.rawurlencode($address);
    }

    /**
     * GET a path under BASE_URL, or an absolute next or delta link URL
     * returned by Graph (its query already included).
     *
     * @param  array<string, scalar>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function get(string $pathOrUrl, array $query = [], array $headers = []): array
    {
        return $this->json($this->send('GET', $pathOrUrl, fn (PendingRequest $request, string $url): Response => $request
            ->withHeaders($headers)
            ->get($url, $query === [] ? null : $query)));
    }

    /**
     * POST a JSON body and return the JSON answer.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->json($this->send('POST', $path, fn (PendingRequest $request, string $url): Response => $request
            ->post($url, $body)));
    }

    /**
     * POST without a body (e.g. /messages/{id}/send, which answers 202).
     */
    public function postEmpty(string $path): void
    {
        $this->send('POST', $path, fn (PendingRequest $request, string $url): Response => $request
            ->withBody('')
            ->post($url));
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path, fn (PendingRequest $request, string $url): Response => $request
            ->delete($url));
    }

    /**
     * @param  callable(PendingRequest, string): Response  $call  Called with the checked URL.
     *
     * @throws GraphRequestFailed
     */
    private function send(string $method, string $pathOrUrl, callable $call): Response
    {
        $url = $this->url($method, $pathOrUrl);
        $operation = $this->operation($url);

        $request = Http::withToken($this->token())
            ->acceptJson()
            ->timeout((int) config('cobro.graph.timeout', 30));

        try {
            $response = $call($request, $url);
        } catch (ConnectionException $e) {
            throw new GraphRequestFailed("Graph {$method} {$operation} did not answer.", previous: $e);
        }

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        if (! $response->successful()) {
            $code = $response->json('error.code');

            throw new GraphRequestFailed(
                "Graph {$method} {$operation} failed with HTTP {$response->status()}".(is_string($code) ? " ({$code})." : '.'),
                $response->status(),
                is_string($code) ? $code : null,
            );
        }

        return $response;
    }

    /**
     * The app-only access token, from the cache or the token endpoint.
     *
     * @throws GraphRequestFailed
     */
    private function token(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                // Cached in plain text before encryption, or under a rotated
                // APP_KEY: ask for a new one.
            }
        }

        if (! self::configured()) {
            throw GraphRequestFailed::notConfigured();
        }

        $tenant = trim((string) config('cobro.graph.tenant_id'));
        $clientId = trim((string) config('cobro.graph.client_id'));
        $secret = (string) config('cobro.graph.client_secret');

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('cobro.graph.timeout', 30))
                ->post('https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token', [
                    'client_id' => $clientId,
                    'client_secret' => $secret,
                    'scope' => self::SCOPE,
                    'grant_type' => 'client_credentials',
                ]);
        } catch (ConnectionException $e) {
            throw new GraphRequestFailed('The Microsoft identity platform did not answer the token request.', previous: $e);
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            $code = $response->json('error');

            throw new GraphRequestFailed(
                "The token request failed with HTTP {$response->status()}".(is_string($code) ? " ({$code})." : '.'),
                $response->status(),
                is_string($code) ? $code : null,
            );
        }

        // Refresh five minutes early; Graph tokens last about an hour.
        $lifetime = max(60, (int) $response->json('expires_in', 3600) - 300);
        Cache::put(self::TOKEN_CACHE_KEY, Crypt::encryptString($token), $lifetime);

        return $token;
    }

    /**
     * A path under BASE_URL, or an absolute URL rebuilt from its parts when
     * it is https, on graph.microsoft.com exactly, without user info or
     * port, under /v1.0/.
     *
     * @throws GraphRequestFailed When an absolute URL is anything else; the
     *                            message names its host, never its query.
     */
    private function url(string $method, string $pathOrUrl): string
    {
        if (! str_contains($pathOrUrl, '://')) {
            return self::BASE_URL.'/'.ltrim($pathOrUrl, '/');
        }

        $parts = preg_match('/[\s\\\\]/', $pathOrUrl) === 1 ? false : parse_url($pathOrUrl);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';

        if (! is_array($parts)
            || mb_strtolower($parts['scheme'] ?? '') !== 'https'
            || mb_strtolower($parts['host'] ?? '') !== self::HOST
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || ! str_starts_with($path, '/v1.0/')) {
            $host = is_array($parts) ? mb_substr(mb_strtolower($parts['host'] ?? ''), 0, 100) : '';

            throw new GraphRequestFailed("Graph {$method} refused a URL outside ".self::BASE_URL." (host \"{$host}\").", graphCode: self::REJECTED_URL);
        }

        return 'https://'.self::HOST.$path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * The path for error messages, without the query (which may hold
     * delta tokens).
     */
    private function operation(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_starts_with($path, '/v1.0') ? substr($path, 5) : $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
