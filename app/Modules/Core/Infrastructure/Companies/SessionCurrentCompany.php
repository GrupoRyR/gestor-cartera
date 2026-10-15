<?php

namespace App\Modules\Core\Infrastructure\Companies;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;
use App\Modules\Core\Infrastructure\Access\CompanyAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * CurrentCompany of the signed-in user, kept in their session.
 *
 * The session stores "SGX", "SDC" or "group" under SESSION_KEY. Without a
 * stored choice, or when it is no longer available, the user works in their
 * first available company; a stored group view needs two companies. Without
 * a session (console, queue) a switch lasts for the current process only.
 */
final class SessionCurrentCompany implements CurrentCompany
{
    public const string SESSION_KEY = 'core.company';

    private const string GROUP = 'group';

    /**
     * The choice made without a session (console, queue).
     */
    private ?string $memory = null;

    /**
     * Available companies of the last user and request: the instance is
     * scoped, but tests and long-lived workers may reuse it across requests.
     *
     * @var array{user: Authenticatable|null, request: Request|null, codes: list<CompanyCode>}|null
     */
    private ?array $resolved = null;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Container $container,
        private readonly CompanyAccess $access,
        private readonly CompanyCatalog $catalog,
    ) {}

    public function active(): ?Company
    {
        $code = $this->activeCode();

        return $code === null ? null : $this->catalog->get($code);
    }

    public function isGroup(): bool
    {
        return $this->availableCodes() !== [] && $this->activeCode() === null;
    }

    public function scope(): array
    {
        $active = $this->active();

        return $active === null ? $this->available() : [$active];
    }

    public function available(): array
    {
        return array_map($this->catalog->get(...), $this->availableCodes());
    }

    public function switchTo(?CompanyCode $code): void
    {
        $available = $this->availableCodes();

        if ($code === null && count($available) < 2) {
            throw CompanyNotAvailable::groupView();
        }

        if ($code !== null && ! in_array($code, $available, true)) {
            throw CompanyNotAvailable::forCompany($code);
        }

        $choice = $code === null ? self::GROUP : $code->value;
        $session = $this->session();

        if ($session === null) {
            $this->memory = $choice;
        } else {
            $session->put(self::SESSION_KEY, $choice);
        }
    }

    public function palette(): BrandPalette
    {
        return $this->active()->palette ?? BrandPalette::neutral();
    }

    public function of(CompanyCode $code): Company
    {
        return $this->catalog->get($code);
    }

    /**
     * The active company; null in the group view and for users without
     * companies.
     */
    private function activeCode(): ?CompanyCode
    {
        $available = $this->availableCodes();

        if ($available === []) {
            return null;
        }

        $session = $this->session();
        $choice = $session === null ? $this->memory : $session->get(self::SESSION_KEY);

        if ($choice === self::GROUP && count($available) > 1) {
            return null;
        }

        $code = is_string($choice) ? CompanyCode::tryFrom($choice) : null;

        return $code !== null && in_array($code, $available, true) ? $code : $available[0];
    }

    /**
     * @return list<CompanyCode>
     */
    private function availableCodes(): array
    {
        $user = $this->auth->guard()->user();
        $request = $this->request();

        if ($this->resolved === null || $this->resolved['user'] !== $user || $this->resolved['request'] !== $request) {
            $this->resolved = ['user' => $user, 'request' => $request, 'codes' => $this->access->companiesFor($user)];
        }

        return $this->resolved['codes'];
    }

    private function session(): ?Session
    {
        $request = $this->request();

        return $request !== null && $request->hasSession() ? $request->session() : null;
    }

    private function request(): ?Request
    {
        $request = $this->container->bound('request') ? $this->container->make('request') : null;

        return $request instanceof Request ? $request : null;
    }
}
