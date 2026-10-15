<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * A sales advisor: a SAP Business One sales employee (OSLP).
 *
 * An advisor is identified by company and code; the same code may exist in
 * both companies.
 */
final readonly class Advisor
{
    /**
     * @param  int  $code  SAP sales employee code (OSLP.SlpCode).
     * @param  string  $name  Name as registered in SAP (OSLP.SlpName).
     * @param  string|null  $email  OSLP.Email; Core links it to a platform user by this address.
     * @param  bool  $active  Whether SAP still lists the advisor as active.
     * @param  int|null  $userId  Platform user linked to this advisor, if any.
     */
    public function __construct(
        public CompanyCode $company,
        public int $code,
        public string $name,
        public ?string $email = null,
        public bool $active = true,
        public ?int $userId = null,
    ) {}
}
