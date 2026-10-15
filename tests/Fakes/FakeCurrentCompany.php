<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\CompanyNotAvailable;

/**
 * In-memory CurrentCompany with the official palettes.
 *
 * new FakeCurrentCompany() is a user in Segurex who may also switch to SDC;
 * FakeCurrentCompany::group() starts in the group view and ::guest() has no
 * company at all.
 */
final class FakeCurrentCompany implements CurrentCompany
{
    private const array DEFAULT_GRACE_DAYS = ['SGX' => 15, 'SDC' => 5];

    private ?CompanyCode $active;

    /**
     * @var list<CompanyCode>
     */
    private array $available;

    /**
     * @var array<string, int>
     */
    private array $graceDays;

    /**
     * @param  list<CompanyCode>  $available
     * @param  array<string, int>  $graceDays  Grace days by company code; missing codes keep 15 (SGX) and 5 (SDC).
     *
     * @throws CompanyNotAvailable When $active is not in $available.
     */
    public function __construct(
        ?CompanyCode $active = CompanyCode::SGX,
        array $available = [CompanyCode::SGX, CompanyCode::SDC],
        array $graceDays = self::DEFAULT_GRACE_DAYS,
    ) {
        $this->available = array_values(array_filter(
            CompanyCode::cases(),
            fn (CompanyCode $code): bool => in_array($code, $available, true),
        ));
        $this->graceDays = array_merge(self::DEFAULT_GRACE_DAYS, $graceDays);

        if ($active !== null && ! in_array($active, $this->available, true)) {
            throw CompanyNotAvailable::forCompany($active);
        }

        $this->active = $active;
    }

    /**
     * A user of both companies in the group view.
     */
    public static function group(): self
    {
        return new self(null);
    }

    /**
     * Nobody signed in (or a user without a role).
     */
    public static function guest(): self
    {
        return new self(null, []);
    }

    public function active(): ?Company
    {
        return $this->active === null ? null : $this->of($this->active);
    }

    public function isGroup(): bool
    {
        return $this->active === null && $this->available !== [];
    }

    public function scope(): array
    {
        return $this->active === null ? $this->available() : [$this->of($this->active)];
    }

    public function available(): array
    {
        return array_map(fn (CompanyCode $code): Company => $this->of($code), $this->available);
    }

    public function switchTo(?CompanyCode $code): void
    {
        if ($code === null) {
            if (count($this->available) < 2) {
                throw CompanyNotAvailable::groupView();
            }
        } elseif (! in_array($code, $this->available, true)) {
            throw CompanyNotAvailable::forCompany($code);
        }

        $this->active = $code;
    }

    public function palette(): BrandPalette
    {
        return $this->active === null ? BrandPalette::neutral() : BrandPalette::official($this->active);
    }

    public function of(CompanyCode $code): Company
    {
        return new Company(
            code: $code,
            name: $code->label(),
            graceDays: $this->graceDays[$code->value],
            palette: BrandPalette::official($code),
        );
    }
}
