<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Parameters\Options\CreditNoteTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\CrossingSellerDate;
use App\Modules\Comisiones\Domain\Parameters\Options\CurrencyTreatment;
use App\Modules\Comisiones\Domain\Parameters\Options\DirectorPlacement;
use App\Modules\Comisiones\Domain\Parameters\Options\SalesBasis;

/**
 * The fields of the settlement options (§14.2–§14.7, SettlementOptions), by
 * their stored name (ParameterMapper "opciones"): the open point each one
 * answers and what it holds. Labels and hints live in
 * resources/lang/es/parametros.php under opciones.campos.<field>.
 */
final class OptionFields
{
    public const string BOOLEAN = 'si_no';

    public const string PERCENT = 'porcentaje';

    /** One of the cases of an option enum (choices()). */
    public const string CHOICE = 'opcion';

    /**
     * field => [open point, CHOICE | BOOLEAN | PERCENT], in the order of §14.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array FIELDS = [
        'base_venta' => ['14.2', self::CHOICE],
        'iva_venta' => ['14.2', self::PERCENT],
        'notas_credito' => ['14.2', self::CHOICE],
        'cruces_en_derivadas' => ['14.3', self::BOOLEAN],
        'cruces_en_directores' => ['14.3', self::BOOLEAN],
        'ubicacion_director' => ['14.4', self::CHOICE],
        'vendedor_cruce' => ['14.5', self::CHOICE],
        'alcance_cumplimiento' => ['14.6', self::CHOICE],
        'moneda' => ['14.7', self::CHOICE],
    ];

    public static function point(string $field): string
    {
        return self::FIELDS[$field][0];
    }

    public static function kind(string $field): string
    {
        return self::FIELDS[$field][1];
    }

    public static function label(string $field): string
    {
        return __("comisiones::parametros.opciones.campos.{$field}.etiqueta");
    }

    public static function hint(string $field): string
    {
        return __("comisiones::parametros.opciones.campos.{$field}.ayuda");
    }

    /**
     * value => label of an enum field; empty for the others.
     *
     * @return array<string, string>
     */
    public static function choices(string $field): array
    {
        $choices = [];

        foreach (self::cases($field) as $case) {
            $choices[$case->value] = $case->label();
        }

        return $choices;
    }

    /**
     * The cases of the enum a CHOICE field holds (SettlementOptions).
     *
     * @return list<SalesBasis|CreditNoteTreatment|DirectorPlacement|CrossingSellerDate|ComplianceScope|CurrencyTreatment>
     */
    private static function cases(string $field): array
    {
        return match ($field) {
            'base_venta' => SalesBasis::cases(),
            'notas_credito' => CreditNoteTreatment::cases(),
            'ubicacion_director' => DirectorPlacement::cases(),
            'vendedor_cruce' => CrossingSellerDate::cases(),
            'alcance_cumplimiento' => ComplianceScope::cases(),
            'moneda' => CurrencyTreatment::cases(),
            default => [],
        };
    }

    /**
     * A stored value as the screen shows it: the enum label, «Sí» / «No» or
     * the percentage.
     */
    public static function display(string $field, mixed $value): string
    {
        return match (self::kind($field)) {
            self::BOOLEAN => __('comisiones::parametros.valores.'.($value === true ? 'si' : 'no')),
            self::PERCENT => ParameterValues::percent($value),
            default => self::choices($field)[is_scalar($value) ? (string) $value : ''] ?? ParameterValues::text($value),
        };
    }
}
