<?php

namespace App\Modules\Core\Console\Migracion;

use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The client of this app (a row of "clients") a legacy row belongs to.
 */
final readonly class TargetClient
{
    /**
     * @param  int|null  $advisorCode  The client's current OSLP.SlpCode.
     */
    public function __construct(
        public int $id,
        public CompanyCode $company,
        public string $code,
        public ?int $advisorCode = null,
    ) {}
}
