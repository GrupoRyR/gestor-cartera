<?php

namespace App\Modules\Core\Contracts\Enums;

use App\Modules\Core\Contracts\Enums\Concerns\HasOptions;

/**
 * The companies of Grupo RYR that share the platform.
 *
 * The value is the short code stored in every table that is scoped by
 * company. The group view ("Grupo") is not a company: contracts represent it
 * with null (see CurrentCompany).
 */
enum CompanyCode: string
{
    use HasOptions;

    /** Segurex Latam SAS. */
    case SGX = 'SGX';

    /** Soluciones y Derivados. */
    case SDC = 'SDC';

    public function label(): string
    {
        return match ($this) {
            self::SGX => 'Segurex Latam',
            self::SDC => 'Soluciones y Derivados',
        };
    }
}
