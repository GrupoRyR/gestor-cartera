<?php

namespace App\Modules\Cobro\Circularizacion;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * The notice Segurex prints under the circularization table: the statement
 * is the one at the cutoff, so a payment made the next month is not in it.
 * Derivados sends no such notice.
 */
final class ClosingNotice
{
    /**
     * The notice in capitals, e.g. "… AL CIERRE DEL 31 DE OCTUBRE. SI
     * REALIZÓ EL PAGO DURANTE EL MES DE NOVIEMBRE …"; null for a company
     * without it or without a cutoff date. The cutoff is read in the
     * application timezone, like {fecha_corte}.
     */
    public static function for(CompanyCode $company, ?CarbonImmutable $cutoff): ?string
    {
        if ($company !== CompanyCode::SGX || $cutoff === null) {
            return null;
        }

        $local = $cutoff->setTimezone(config()->string('app.timezone'))->locale('es');

        // Lowercase values: the :UPPERCASE replacements write them in capitals.
        return (string) __('cobro::circularizacion.segurex.aviso_cierre', [
            'dia_mes' => "{$local->day} de {$local->monthName}",
            'mes_corte' => $local->monthName,
            'mes_siguiente' => $local->addDay()->monthName,
        ]);
    }
}
