<?php

use App\Modules\Cobro\Infrastructure\Graph\GraphClient;
use App\Modules\Cobro\Infrastructure\Graph\GraphCredentials;
use App\Modules\Cobro\Infrastructure\Graph\GraphRequestFailed;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\Modules\Cobro\Support\GraphFake;

const COBRO_SGX_INBOX = 'users/segurex.info@segurex.com/messages';

const COBRO_SDC_INBOX = 'users/derivados.info@derivadoscol.com/messages';

/**
 * Graph answering an empty message list for both company mailboxes.
 */
function cobroBothInboxes(): GraphFake
{
    return GraphFake::install()
        ->on('GET', COBRO_SGX_INBOX, ['value' => []])
        ->on('GET', COBRO_SDC_INBOX, ['value' => []]);
}

/**
 * The client of $company, as the callers get it.
 */
function cobroGraph(CompanyCode $company): GraphClient
{
    return app(GraphClient::class)->for($company);
}

it('uses the default credential for both companies with a single token', function () {
    $graph = cobroBothInboxes();

    cobroGraph(CompanyCode::SGX)->get(COBRO_SGX_INBOX);
    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);

    $token = GraphFake::fixture('token.json')['access_token'];

    expect($graph->tokenRequests())->toBe(1)
        ->and($graph->tokenRequestsTo(GraphFake::TENANT_ID))->toHaveCount(1)
        ->and($graph->bearers())->toBe([$token, $token])
        ->and(Crypt::decryptString(Cache::get(GraphFake::tokenCacheKey())))->toBe($token)
        ->and(Cache::has('cobro.graph.token'))->toBeFalse();
});

it('drops the token cached under the old single key when it asks for a new one', function () {
    cobroBothInboxes();
    Cache::put('cobro.graph.token', Crypt::encryptString('legacy-shared-token'), 3600);

    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);

    expect(Cache::has('cobro.graph.token'))->toBeFalse()
        ->and(Cache::has(GraphFake::tokenCacheKey()))->toBeTrue();
});

it('keeps the secret out of the token cache key', function () {
    cobroBothInboxes();

    cobroGraph(CompanyCode::SGX)->get(COBRO_SGX_INBOX);

    expect(GraphFake::tokenCacheKey())->not->toContain(GraphFake::CLIENT_SECRET)
        ->and(GraphCredentials::for(CompanyCode::SGX)?->cacheKey())->toBe(GraphFake::tokenCacheKey());
});

it('falls back to the default credential unless the company sets all three values', function (?string $tenant, ?string $client, ?string $secret) {
    $graph = cobroBothInboxes()->withCompanyCredential(CompanyCode::SDC, $tenant, $client, $secret, GraphFake::SDC_TOKEN);

    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);

    [$request] = $graph->tokenRequestsTo(GraphFake::TENANT_ID);

    expect($graph->tokenRequests())->toBe(1)
        ->and($request->data()['client_id'])->toBe(GraphFake::CLIENT_ID)
        ->and($graph->bearers())->toBe([GraphFake::fixture('token.json')['access_token']]);
})->with([
    'blank secret' => [GraphFake::SDC_TENANT_ID, GraphFake::SDC_CLIENT_ID, '   '],
    'no tenant' => [null, GraphFake::SDC_CLIENT_ID, GraphFake::SDC_CLIENT_SECRET],
    'blank client id' => [GraphFake::SDC_TENANT_ID, '', GraphFake::SDC_CLIENT_SECRET],
]);

it('asks each company\'s own tenant for its token and sends each mailbox only its own', function () {
    $graph = cobroBothInboxes()->withSeparateTenants();

    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);
    cobroGraph(CompanyCode::SGX)->get(COBRO_SGX_INBOX);
    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);

    [$sdc] = $graph->tokenRequestsTo(GraphFake::SDC_TENANT_ID);
    [$sgx] = $graph->tokenRequestsTo(GraphFake::SGX_TENANT_ID);

    expect($graph->tokenRequests())->toBe(2)
        ->and($sdc->url())->toBe('https://login.microsoftonline.com/'.GraphFake::SDC_TENANT_ID.'/oauth2/v2.0/token')
        ->and($sdc->data())->toMatchArray(['client_id' => GraphFake::SDC_CLIENT_ID, 'client_secret' => GraphFake::SDC_CLIENT_SECRET])
        ->and($sgx->data())->toMatchArray(['client_id' => GraphFake::SGX_CLIENT_ID, 'client_secret' => GraphFake::SGX_CLIENT_SECRET])
        ->and($graph->bearers(COBRO_SDC_INBOX))->toBe([GraphFake::SDC_TOKEN, GraphFake::SDC_TOKEN])
        ->and($graph->bearers(COBRO_SGX_INBOX))->toBe([GraphFake::SGX_TOKEN]);
});

it('never uses a cached SGX token for an SDC request', function () {
    $graph = cobroBothInboxes()->withSeparateTenants();

    cobroGraph(CompanyCode::SGX)->get(COBRO_SGX_INBOX);
    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);

    $sgxKey = GraphFake::tokenCacheKey(GraphFake::SGX_TENANT_ID, GraphFake::SGX_CLIENT_ID);
    $sdcKey = GraphFake::tokenCacheKey(GraphFake::SDC_TENANT_ID, GraphFake::SDC_CLIENT_ID);

    expect($graph->tokenRequestsTo(GraphFake::SDC_TENANT_ID))->toHaveCount(1)
        ->and($graph->bearers(COBRO_SDC_INBOX))->toBe([GraphFake::SDC_TOKEN])
        ->and(Crypt::decryptString(Cache::get($sgxKey)))->toBe(GraphFake::SGX_TOKEN)
        ->and(Crypt::decryptString(Cache::get($sdcKey)))->toBe(GraphFake::SDC_TOKEN);
});

it('uses an SDC override while SGX keeps the default credential', function () {
    $graph = cobroBothInboxes()->withCompanyCredential(
        CompanyCode::SDC, GraphFake::SDC_TENANT_ID, GraphFake::SDC_CLIENT_ID, GraphFake::SDC_CLIENT_SECRET, GraphFake::SDC_TOKEN,
    );

    cobroGraph(CompanyCode::SGX)->get(COBRO_SGX_INBOX);
    cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);

    expect($graph->tokenRequestsTo(GraphFake::TENANT_ID))->toHaveCount(1)
        ->and($graph->tokenRequestsTo(GraphFake::SDC_TENANT_ID))->toHaveCount(1)
        ->and($graph->bearers(COBRO_SGX_INBOX))->toBe([GraphFake::fixture('token.json')['access_token']])
        ->and($graph->bearers(COBRO_SDC_INBOX))->toBe([GraphFake::SDC_TOKEN]);
});

it('forgets only the token of the credential Graph answered 401 for', function () {
    GraphFake::install()->withSeparateTenants()
        ->on('GET', COBRO_SGX_INBOX, ['value' => []])
        ->on('GET', COBRO_SDC_INBOX, GraphFake::fixture('error-access-denied.json'), 401);

    cobroGraph(CompanyCode::SGX)->get(COBRO_SGX_INBOX);

    expect(fn () => cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX))->toThrow(GraphRequestFailed::class, 'HTTP 401');

    expect(Cache::has(GraphFake::tokenCacheKey(GraphFake::SGX_TENANT_ID, GraphFake::SGX_CLIENT_ID)))->toBeTrue()
        ->and(Cache::has(GraphFake::tokenCacheKey(GraphFake::SDC_TENANT_ID, GraphFake::SDC_CLIENT_ID)))->toBeFalse();
});

it('says whether each company has a complete credential', function () {
    $graph = GraphFake::install();

    expect(GraphClient::configured(CompanyCode::SGX))->toBeTrue()
        ->and(GraphClient::configured(CompanyCode::SDC))->toBeTrue();

    config(['cobro.graph.client_secret' => '']);

    expect(GraphClient::configured(CompanyCode::SGX))->toBeFalse()
        ->and(GraphClient::configured(CompanyCode::SDC))->toBeFalse();

    $graph->withCompanyCredential(CompanyCode::SDC, GraphFake::SDC_TENANT_ID, GraphFake::SDC_CLIENT_ID, GraphFake::SDC_CLIENT_SECRET);

    expect(GraphClient::configured(CompanyCode::SGX))->toBeFalse()
        ->and(GraphClient::configured(CompanyCode::SDC))->toBeTrue();
});

it('refuses a company without a credential before any request, naming it and never a secret', function () {
    $graph = cobroBothInboxes()->withCompanyCredential(
        CompanyCode::SGX, GraphFake::SGX_TENANT_ID, GraphFake::SGX_CLIENT_ID, GraphFake::SGX_CLIENT_SECRET, GraphFake::SGX_TOKEN,
    );
    config(['cobro.graph.client_secret' => null]);

    try {
        cobroGraph(CompanyCode::SDC)->get(COBRO_SDC_INBOX);
        $this->fail('GraphRequestFailed was not thrown.');
    } catch (GraphRequestFailed $e) {
        expect($e->graphCode)->toBe(GraphRequestFailed::NOT_CONFIGURED)
            ->and($e->getMessage())->toContain('Microsoft Graph is not configured for SDC')
            ->toContain('GRAPH_TENANT_ID, GRAPH_CLIENT_ID and GRAPH_CLIENT_SECRET')
            ->toContain('GRAPH_SDC_TENANT_ID, GRAPH_SDC_CLIENT_ID and GRAPH_SDC_CLIENT_SECRET')
            ->not->toContain(GraphFake::SGX_CLIENT_SECRET)
            ->not->toContain(GraphFake::SGX_TENANT_ID);
    }

    expect($graph->tokenRequests())->toBe(0)
        ->and($graph->requests())->toBe([]);
});

it('refuses every request of a client not bound to a company', function (Closure $call) {
    $graph = cobroBothInboxes();

    expect(fn () => $call(app(GraphClient::class)))->toThrow(LogicException::class);

    expect($graph->tokenRequests())->toBe(0)
        ->and($graph->requests())->toBe([]);
})->with([
    'get' => [fn (GraphClient $graph) => $graph->get(COBRO_SGX_INBOX)],
    'post' => [fn (GraphClient $graph) => $graph->post(COBRO_SGX_INBOX, [])],
    'postEmpty' => [fn (GraphClient $graph) => $graph->postEmpty(COBRO_SGX_INBOX.'/1/send')],
    'delete' => [fn (GraphClient $graph) => $graph->delete(COBRO_SGX_INBOX.'/1')],
]);

it('leaves the shared client unbound when binding a company', function () {
    $shared = app(GraphClient::class);
    $bound = $shared->for(CompanyCode::SDC);

    expect($bound)->not->toBe($shared)
        ->and(fn () => $shared->get(COBRO_SGX_INBOX))->toThrow(LogicException::class);
});
