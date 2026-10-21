<?php

namespace App\Modules\Comisiones\Infrastructure\Report;

use App\Modules\Comisiones\Domain\Settlement\SettlementStatus;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Domain\Shared\Period;
use DateTimeImmutable;

/**
 * Spanish texts shared by the screens and the exports, so the consolidated
 * report reads the same on screen, in Excel and in the PDF.
 *
 * Domain dates are calendar days at UTC midnight: they are formatted as
 * they are, never converted to Bogotá time (which would show the day before).
 */
final class Labels
{
    /** "julio de 2026". */
    public static function month(Period $period): string
    {
        return __('comisiones::comun.mes_anio', [
            'mes' => __('comisiones::comun.meses.'.$period->month),
            'anio' => $period->year,
        ]);
    }

    /** "Informe de comisiones — período calculado del 01 al 31 de julio de 2026" (§8.1, sentence case). */
    public static function reportTitle(Period $period): string
    {
        return __('comisiones::comun.titulo_informe', [
            'desde' => $period->start()->format('d'),
            'hasta' => $period->end()->format('d'),
            'mes' => __('comisiones::comun.meses.'.$period->month),
            'anio' => $period->year,
        ]);
    }

    /**
     * The status as the reports name it: a version that is not closed says so.
     */
    public static function status(SettlementStatus $status): string
    {
        return __('comisiones::comun.estados.'.$status->value);
    }

    /** Chip tone of a status: draft in progress, approved waiting, closed done. */
    public static function statusTone(SettlementStatus $status): string
    {
        return match ($status) {
            SettlementStatus::Draft => 'warning',
            SettlementStatus::Approved => 'info',
            SettlementStatus::Closed => 'success',
        };
    }

    /** A ratio such as the compliance: "90,51 %". */
    public static function percent(Percentage $percentage, int $decimals = 2): string
    {
        return number_format((float) $percentage->percent(), $decimals, ',', '.').' %';
    }

    /** A parameter rate as written, without padding: "0,75 %", "1,5 %", "100 %". */
    public static function rate(Percentage $percentage): string
    {
        return str_replace('.', ',', $percentage->percent()).' %';
    }

    /** A calendar day of the engine: "15/07/2026". */
    public static function day(DateTimeImmutable $date): string
    {
        return $date->format('d/m/Y');
    }

    /** A calendar day of the engine as "Y-m-d", for <x-ui.date>, which reads it as a local day. */
    public static function isoDay(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
