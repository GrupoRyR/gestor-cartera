<?php

namespace App\Modules\Cobro\Infrastructure\Graph;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * Thin Microsoft Graph v1.0 client over Laravel's HTTP client. No SDK:
 * tests fake every call with Http::fake() and recorded responses.
 *
 * Each company mailbox may live in another tenant, so a request always
 * runs with the credential of one company: for($company) returns a client
 * bound to it (GraphCredentials: the company's override, else the default
 * of config cobro.graph), and the injected, unbound client refuses every
 * request with a LogicException. The app-only token is cached per
 * credential (tenant and client id, never the secret), encrypted with the
 * APP_KEY, until a few minutes before it expires; companies on the same
 * credential share it, and a token is never sent for another credential.
 *
 * Every failure (not configured, no connection, a non-2xx answer) becomes a
 * GraphRequestFailed without secrets. A 401 also drops the cached token of
 * that credential only, so its next call asks for a new one. An absolute
 * URL (a next or delta link) is followed only under BASE_URL; any other one
 * is refused before the token is read, so the token never goes to another
 * host.
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

    /**
     * The single token key used before tokens were cached per credential;
     * dropped whenever a new token is stored, so it does not linger.
     */
    private const string LEGACY_TOKEN_CACHE_KEY = 'cobro.graph.token';

    /**
     * @param  CompanyCode|null  $company  The company whose credential every request uses; null refuses them.
     */
    public function __construct(private readonly ?CompanyCode $company = null) {}

    /**
     * Whether $company has a complete credential (its own or the default).
     */
    public static function configured(CompanyCode $company): bool
    {
        return GraphCredentials::for($company) !== null;
    }

    /**
     * A client whose requests use the credential of $company. This client
     * stays as it was.
     */
    public function for(CompanyCode $company): self
    {
        return new self($company);
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
     * @throws LogicException When the client is not bound to a company.
     * @throws GraphRequestFailed
     */
    private function send(string $method, string $pathOrUrl, callable $call): Response
    {
        $company = $this->company ?? throw new LogicException('GraphClient is not bound to a company: call for($company) first.');
        $url = $this->url($method, $pathOrUrl);
        $operation = $this->operation($url);
        $credentials = GraphCredentials::for($company) ?? throw GraphRequestFailed::notConfigured($company);

        $request = Http::withToken($this->token($credentials))
            ->acceptJson()
            ->timeout((int) config('cobro.graph.timeout', 30));

        try {
            $response = $call($request, $url);
        } catch (ConnectionException $e) {
            throw new GraphRequestFailed("Graph {$method} {$operation} did not answer.", previous: $e);
        }

        if ($response->status() === 401) {
            Cache::forget($credentials->cacheKey());
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
     * The app-only access token of $credentials, from its cache key or the
     * token endpoint of its tenant.
     *
     * @throws GraphRequestFailed
     */
    private function token(GraphCredentials $credentials): string
    {
        $cached = Cache::get($credentials->cacheKey());

        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (DecryptException) {
                // Cached in plain text before encryption, or under a rotated
                // APP_KEY: ask for a new one.
            }
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout((int) config('cobro.graph.timeout', 30))
                ->post('https://login.microsoftonline.com/'.rawurlencode($credentials->tenantId).'/oauth2/v2.0/token', [
                    'client_id' => $credentials->clientId,
                    'client_secret' => $credentials->clientSecret,
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
        Cache::put($credentials->cacheKey(), Crypt::encryptString($token), $lifetime);
        Cache::forget(self::LEGACY_TOKEN_CACHE_KEY);

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
