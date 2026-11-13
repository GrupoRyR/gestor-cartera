<?php

namespace Tests\Modules\Cobro\Support;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fakes Microsoft Graph and its token endpoint with recorded responses
 * (tests/Modules/Cobro/Fixtures/graph). Routes match the method and the
 * URL path after /v1.0 with Str::is patterns, first match wins; an
 * unrouted call fails the test.
 *
 *     $graph = GraphFake::install()
 *         ->on('POST', 'users/*\/messages', GraphFake::fixture('draft-created.json'))
 *         ->on('POST', 'users/*\/messages/*\/send', status: 202);
 *
 * install() sets only the default credential (cobro.graph) and blanks the
 * per-company ones; withCompanyCredential() or withSeparateTenants() set
 * them, and the token endpoint answers each tenant with its own token.
 */
final class GraphFake
{
    /**
     * Status of a route that never answers (a connection timeout).
     */
    public const int NO_ANSWER = 0;

    /**
     * Default credential (config cobro.graph), answered with token.json.
     */
    public const string TENANT_ID = '7c1d3e1f-0000-4000-8000-000000000001';

    public const string CLIENT_ID = '7c1d3e1f-0000-4000-8000-000000000002';

    public const string CLIENT_SECRET = 'recorded-fixture-secret';

    /**
     * Separate app registrations of withSeparateTenants().
     */
    public const string SGX_TENANT_ID = '3a9b6c11-0000-4000-8000-0000000000a1';

    public const string SGX_CLIENT_ID = '3a9b6c11-0000-4000-8000-0000000000a2';

    public const string SGX_CLIENT_SECRET = 'sgx-fixture-secret';

    public const string SGX_TOKEN = 'eyJ0eXAiOiJKV1QifQ.sgx-tenant.signature';

    public const string SDC_TENANT_ID = '5e2d4f80-0000-4000-8000-0000000000b1';

    public const string SDC_CLIENT_ID = '5e2d4f80-0000-4000-8000-0000000000b2';

    public const string SDC_CLIENT_SECRET = 'sdc-fixture-secret';

    public const string SDC_TOKEN = 'eyJ0eXAiOiJKV1QifQ.sdc-tenant.signature';

    /**
     * @var list<array{method: string, path: string, queue: list<array{0: array<string, mixed>|null, 1: int}>}>
     */
    private array $routes = [];

    /**
     * @var list<Request>
     */
    private array $graphRequests = [];

    /**
     * @var list<Request>
     */
    private array $tokenRequestList = [];

    /**
     * Access token the token endpoint answers per tenant id.
     *
     * @var array<string, string>
     */
    private array $tenantTokens = [];

    /**
     * Sets the Graph credentials and fakes HTTP; the token endpoint answers
     * with the recorded token unless $tokenFixture names another file.
     */
    public static function install(string $tokenFixture = 'token.json', int $tokenStatus = 200): self
    {
        $blank = ['tenant_id' => null, 'client_id' => null, 'client_secret' => null];

        config([
            'cobro.graph.tenant_id' => self::TENANT_ID,
            'cobro.graph.client_id' => self::CLIENT_ID,
            'cobro.graph.client_secret' => self::CLIENT_SECRET,
            'cobro.graph.companies' => ['SGX' => $blank, 'SDC' => $blank],
            'cobro.mailboxes.SGX' => 'segurex.info@segurex.com',
            'cobro.mailboxes.SDC' => 'derivados.info@derivadoscol.com',
        ]);

        $fake = new self;

        Http::fake(function (Request $request) use ($fake, $tokenFixture, $tokenStatus): PromiseInterface {
            if (str_starts_with($request->url(), 'https://login.microsoftonline.com/')) {
                $fake->tokenRequestList[] = $request;
                $token = $fake->tenantTokens[self::tenant($request)] ?? null;

                return $token === null
                    ? Http::response(self::fixture($tokenFixture), $tokenStatus)
                    : Http::response(['token_type' => 'Bearer', 'expires_in' => 3599, 'access_token' => $token]);
            }

            return $fake->answer($request);
        });

        return $fake;
    }

    /**
     * Cache key of the token of a credential: one per tenant and client id.
     */
    public static function tokenCacheKey(string $tenantId = self::TENANT_ID, string $clientId = self::CLIENT_ID): string
    {
        return 'cobro.graph.token.'.hash('sha256', $tenantId.'|'.$clientId);
    }

    /**
     * Sets the per-company credential of $company (cobro.graph.companies);
     * with $accessToken, the token endpoint of $tenantId answers it.
     */
    public function withCompanyCredential(CompanyCode $company, ?string $tenantId, ?string $clientId, ?string $secret, ?string $accessToken = null): self
    {
        config(["cobro.graph.companies.{$company->value}" => [
            'tenant_id' => $tenantId,
            'client_id' => $clientId,
            'client_secret' => $secret,
        ]]);

        if ($accessToken !== null && $tenantId !== null) {
            $this->tenantTokens[$tenantId] = $accessToken;
        }

        return $this;
    }

    /**
     * SGX and SDC each with its own app registration in its own tenant,
     * answered with SGX_TOKEN and SDC_TOKEN.
     */
    public function withSeparateTenants(): self
    {
        return $this
            ->withCompanyCredential(CompanyCode::SGX, self::SGX_TENANT_ID, self::SGX_CLIENT_ID, self::SGX_CLIENT_SECRET, self::SGX_TOKEN)
            ->withCompanyCredential(CompanyCode::SDC, self::SDC_TENANT_ID, self::SDC_CLIENT_ID, self::SDC_CLIENT_SECRET, self::SDC_TOKEN);
    }

    /**
     * The decoded JSON of a recorded response.
     *
     * @return array<string, mixed>
     */
    public static function fixture(string $name): array
    {
        $json = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/graph/'.$name), true, flags: JSON_THROW_ON_ERROR);

        return is_array($json) ? $json : throw new RuntimeException("Fixture {$name} is not an object.");
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public function on(string $method, string $path, ?array $body = null, int $status = 200): self
    {
        $this->routes[] = ['method' => $method, 'path' => $path, 'queue' => [[$body, $status]]];

        return $this;
    }

    /**
     * Answers successive matching requests with these [body, status] pairs
     * in order; the last one repeats.
     *
     * @param  array{0: array<string, mixed>|null, 1: int}  ...$responses
     */
    public function onEach(string $method, string $path, array ...$responses): self
    {
        $this->routes[] = ['method' => $method, 'path' => $path, 'queue' => array_values($responses)];

        return $this;
    }

    /**
     * Graph requests (not the token requests), in the order they were made.
     *
     * @return list<Request>
     */
    public function requests(): array
    {
        return $this->graphRequests;
    }

    /**
     * Graph requests of this method whose path matches the pattern.
     *
     * @return list<Request>
     */
    public function requestsTo(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->graphRequests,
            fn (Request $request): bool => $request->method() === $method && Str::is($path, self::path($request)),
        ));
    }

    public function tokenRequests(): int
    {
        return count($this->tokenRequestList);
    }

    /**
     * Token requests made to the token endpoint of $tenantId.
     *
     * @return list<Request>
     */
    public function tokenRequestsTo(string $tenantId): array
    {
        return array_values(array_filter($this->tokenRequestList, fn (Request $request): bool => self::tenant($request) === $tenantId));
    }

    /**
     * The bearer token each Graph request whose path matches carried, in
     * order.
     *
     * @return list<string>
     */
    public function bearers(string $path = '*'): array
    {
        return array_values(array_map(
            fn (Request $request): string => Str::after($request->header('Authorization')[0] ?? '', 'Bearer '),
            array_filter($this->graphRequests, fn (Request $request): bool => Str::is($path, self::path($request))),
        ));
    }

    private function answer(Request $request): PromiseInterface
    {
        $this->graphRequests[] = $request;
        $path = self::path($request);

        foreach ($this->routes as $index => $route) {
            if ($route['method'] === $request->method() && Str::is($route['path'], $path)) {
                [$body, $status] = count($route['queue']) > 1
                    ? array_shift($this->routes[$index]['queue'])
                    : $route['queue'][0];

                return $status === self::NO_ANSWER
                    ? (Http::failedConnection('Operation timed out after 30001 milliseconds'))($request)
                    : Http::response($body, $status);
            }
        }

        throw new RuntimeException("Unrouted Graph request: {$request->method()} {$path}");
    }

    /**
     * The tenant segment of a token endpoint URL.
     */
    private static function tenant(Request $request): string
    {
        return rawurldecode(explode('/', ltrim((string) parse_url($request->url(), PHP_URL_PATH), '/'))[0]);
    }

    /**
     * The URL path after /v1.0/, decoded (users/segurex.info@segurex.com/messages).
     */
    private static function path(Request $request): string
    {
        $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));

        return ltrim((string) preg_replace('#^/v1\.0#', '', $path), '/');
    }
}
