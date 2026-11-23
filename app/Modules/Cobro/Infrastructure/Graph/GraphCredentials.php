<?php

namespace App\Modules\Cobro\Infrastructure\Graph;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use SensitiveParameter;

/**
 * The app registration (tenant, client id and secret) that reaches the
 * mailbox of one company: its own override in cobro.graph.companies when
 * all three values are set, otherwise the default of cobro.graph, but only
 * for the companies in cobro.graph.default_companies (the default lives in
 * their tenant; another tenant answers its token with ErrorInvalidUser).
 *
 * The secret never goes into the cache key, a log or an exception message.
 */
final readonly class GraphCredentials
{
    private function __construct(
        public string $tenantId,
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
    ) {}

    /**
     * The credential of $company, or null when its override is not complete
     * and the default is not complete or not meant for it.
     */
    public static function for(CompanyCode $company): ?self
    {
        return self::complete((array) config("cobro.graph.companies.{$company->value}", []))
            ?? (self::usesDefault($company) ? self::complete((array) config('cobro.graph', [])) : null);
    }

    /**
     * Whether $company may fall back to the default credential.
     */
    public static function usesDefault(CompanyCode $company): bool
    {
        return in_array($company->value, (array) config('cobro.graph.default_companies', []), true);
    }

    /**
     * Cache key of this credential's token: one per tenant and client id, so
     * companies on the same app registration share its token.
     */
    public function cacheKey(): string
    {
        return 'cobro.graph.token.'.hash('sha256', $this->tenantId.'|'.$this->clientId);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function complete(array $values): ?self
    {
        $tenantId = trim(self::string($values, 'tenant_id'));
        $clientId = trim(self::string($values, 'client_id'));
        $secret = self::string($values, 'client_secret');

        return $tenantId === '' || $clientId === '' || trim($secret) === ''
            ? null
            : new self($tenantId, $clientId, $secret);
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
