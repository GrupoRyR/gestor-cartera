<?php

namespace App\Modules\Core\Application\Companies;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Companies\CompanyCatalog;

/**
 * One company as the Parámetros screen shows it: its name and the
 * parameters of the client statuses that admins edit, read from
 * CompanyCatalog.
 */
final readonly class CompanyParameters
{
    public function __construct(
        public CompanyCode $code,
        public string $name,
        public int $graceDays,
        public int $dueSoonDays,
    ) {}

    public static function of(CompanyCatalog $catalog, CompanyCode $code): self
    {
        $parameters = $catalog->parameters($code);

        return new self($code, $catalog->get($code)->name, $parameters->graceDays, $parameters->dueSoonDays);
    }

    /**
     * Every company, in CompanyCode order.
     *
     * @return list<self>
     */
    public static function all(CompanyCatalog $catalog): array
    {
        return array_map(fn (CompanyCode $code): self => self::of($catalog, $code), CompanyCode::cases());
    }
}
