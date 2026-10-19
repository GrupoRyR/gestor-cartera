<?php

namespace App\Modules\Cobro\Infrastructure\Graph;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin Microsoft Graph v1.0 client over Laravel's HTTP client, with an
 * app-only token (client credentials of config cobro.graph) cached until a
 * few minutes before it expires. No SDK: tests fake every call with
 * Http::fake() and recorded responses.
 *
 * Every failure (not configured, no connection, a non-2xx answer) becomes a
 * GraphRequestFailed without secrets. A 401 also drops the cached token so
 * the next call asks for a new one.
 */
final class GraphClient
{
    public const string BASE_URL = 'https://graph.microsoft.com/v1.0';

    public const string SCOPE = 'https://graph.microsoft.com/.default';

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
        return $this->json($this->send('GET', $pathOrUrl, fn (PendingRequest $request): Response => $request
            ->withHeaders($headers)
            ->get($this->url($pathOrUrl), $query === [] ? null : $query)));
    }

    /**
     * POST a JSON body and return the JSON answer.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->json($this->send('POST', $path, fn (PendingRequest $request): Response => $request
            ->post($this->url($path), $body)));
    }

    /**
     * POST without a body (e.g. /messages/{id}/send, which answers 202).
     */
    public function postEmpty(string $path): void
    {
        $this->send('POST', $path, fn (PendingRequest $request): Response => $request
            ->withBody('')
            ->post($this->url($path)));
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path, fn (PendingRequest $request): Response => $request
            ->delete($this->url($path)));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws GraphRequestFailed
     */
    private function send(string $method, string $pathOrUrl, callable $call): Response
    {
        $request = Http::withToken($this->token())
            ->acceptJson()
            ->timeout((int) config('cobro.graph.timeout', 30));

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw new GraphRequestFailed("Graph {$method} {$this->operation($pathOrUrl)} did not answer.", previous: $e);
        }

        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE_KEY);
        }

        if (! $response->successful()) {
            $code = $response->json('error.code');

            throw new GraphRequestFailed(
                "Graph {$method} {$this->operation($pathOrUrl)} failed with HTTP {$response->status()}".(is_string($code) ? " ({$code})." : '.'),
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
            return $cached;
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
        Cache::put(self::TOKEN_CACHE_KEY, $token, $lifetime);

        return $token;
    }

    private function url(string $pathOrUrl): string
    {
        return str_starts_with($pathOrUrl, 'https://') ? $pathOrUrl : self::BASE_URL.'/'.ltrim($pathOrUrl, '/');
    }

    /**
     * The path for error messages, without the query (which may hold
     * delta tokens).
     */
    private function operation(string $pathOrUrl): string
    {
        $path = (string) parse_url($this->url($pathOrUrl), PHP_URL_PATH);

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
