<?php

namespace App\Modules\Cobro\Infrastructure\Copies;

use App\Modules\Cobro\Domain\Copies\CopySettings;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The carbon copies of each company's weekly collection mail, from config
 * cobro.copias.<company>.
 */
final class CopyStore
{
    public function for(CompanyCode $company): CopySettings
    {
        return CopySettings::fromArray((array) config("cobro.copias.{$company->value}", []));
    }
}
