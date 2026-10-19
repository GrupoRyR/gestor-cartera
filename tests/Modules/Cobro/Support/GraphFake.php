<?php

namespace Tests\Modules\Cobro\Support;

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
 */
final class GraphFake
{
    /**
     * @var list<array{method: string, path: string, queue: list<array{0: array<string, mixed>|null, 1: int}>}>
     */
    private array $routes = [];

    /**
     * @var list<Request>
     */
    private array $graphRequests = [];

    private int $tokenRequests = 0;

    /**
     * Sets the Graph credentials and fakes HTTP; the token endpoint answers
     * with the recorded token unless $tokenFixture names another file.
     */
    public static function install(string $tokenFixture = 'token.json', int $tokenStatus = 200): self
    {
        config([
            'cobro.graph.tenant_id' => '7c1d3e1f-0000-4000-8000-000000000001',
            'cobro.graph.client_id' => '7c1d3e1f-0000-4000-8000-000000000002',
            'cobro.graph.client_secret' => 'recorded-fixture-secret',
            'cobro.mailboxes.SGX' => 'segurex.info@segurex.com',
            'cobro.mailboxes.SDC' => 'derivados.info@derivadoscol.com',
        ]);

        $fake = new self;

        Http::fake(function (Request $request) use ($fake, $tokenFixture, $tokenStatus): PromiseInterface {
            if (str_starts_with($request->url(), 'https://login.microsoftonline.com/')) {
                $fake->tokenRequests++;

                return Http::response(self::fixture($tokenFixture), $tokenStatus);
            }

            return $fake->answer($request);
        });

        return $fake;
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
        return $this->tokenRequests;
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

                return Http::response($body, $status);
            }
        }

        throw new RuntimeException("Unrouted Graph request: {$request->method()} {$path}");
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
