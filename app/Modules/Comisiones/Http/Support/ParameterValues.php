<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Domain\Parameters\CodeScope;
use App\Modules\Comisiones\Domain\Parameters\DerivedBase;
use App\Modules\Comisiones\Domain\Shared\Percentage;
use App\Modules\Comisiones\Infrastructure\Report\Labels;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The stored "datos" of a parameter version (ParameterMapper) as the
 * «Parámetros» screen shows them: «0,75 %», «Sin límite», «Regular»… A
 * value that does not read as expected is shown as it is, never hidden.
 */
final class ParameterValues
{
    /** A stored percent string ("0.75") as «0,75 %». */
    public static function percent(mixed $value): string
    {
        if (! is_string($value) && ! is_int($value)) {
            return self::text($value);
        }

        try {
            return Labels::rate(Percentage::of($value));
        } catch (InvalidArgumentException) {
            return self::text($value);
        }
    }

    /** A stored percent string as a person types it back: "0.75" → «0,75». */
    public static function percentInput(mixed $value): string
    {
        return is_string($value) || is_int($value) ? str_replace('.', ',', (string) $value) : '';
    }

    /** Any scalar as text; «—» for nothing. */
    public static function text(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : __('comisiones::comun.sin_dato');
    }

    /** The name of a stored person {id, nombre}; null without one. */
    public static function person(mixed $person): ?string
    {
        return is_array($person) && is_string($person['nombre'] ?? null) ? $person['nombre'] : null;
    }

    /** The id of a stored person {id, nombre}; null without one. */
    public static function personId(mixed $person): ?string
    {
        return is_array($person) && is_string($person['id'] ?? null) ? $person['id'] : null;
    }

    public static function scope(mixed $value): string
    {
        return (is_string($value) ? CodeScope::tryFrom($value)?->label() : null) ?? self::text($value);
    }

    public static function base(mixed $value): string
    {
        return (is_string($value) ? DerivedBase::tryFrom($value)?->label() : null) ?? self::text($value);
    }

    /** «Con tramos» or «Sin tramos». */
    public static function tranches(mixed $usesTranches): string
    {
        return __('comisiones::parametros.valores.'.($usesTranches === false ? 'sin_tramos' : 'con_tramos'));
    }

    /** The upper day of a tranche, «Sin límite» when open-ended. */
    public static function until(mixed $days): string
    {
        return $days === null ? __('comisiones::parametros.valores.sin_limite') : self::text($days);
    }

    /**
     * A stored tranche as one line: «0 a 65 días: 100 %», «126 días o más: 0 %».
     *
     * @param  array<array-key, mixed>  $tranche
     */
    public static function tranche(array $tranche): string
    {
        $from = self::text($tranche['desde'] ?? null);
        $share = self::percent($tranche['participacion'] ?? null);

        return ($tranche['hasta'] ?? null) === null
            ? __('comisiones::parametros.valores.tramo_abierto', ['desde' => $from, 'participacion' => $share])
            : __('comisiones::parametros.valores.tramo', ['desde' => $from, 'hasta' => self::text($tranche['hasta']), 'participacion' => $share]);
    }

    /** The codes of a team, «14, 15, 16». */
    public static function members(mixed $codes): string
    {
        return is_array($codes) ? implode(', ', array_map(fn (mixed $code): string => self::text($code), $codes)) : self::text($codes);
    }

    /** A validity day, «01/11/2026». */
    public static function day(?DateTimeInterface $day): string
    {
        return $day === null ? __('comisiones::comun.sin_dato') : $day->format('d/m/Y');
    }

    /**
     * The values of a version, one «Etiqueta: valor» line each, for its history.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function lines(ParameterKind $kind, array $data): array
    {
        $line = fn (string $field, string $value): string => __('comisiones::parametros.campos.'.$field).': '.$value;

        return match ($kind) {
            ParameterKind::Code => [
                $line('nombre', self::text($data['nombre'] ?? null)),
                $line('persona', self::person($data['persona'] ?? null) ?? __('comisiones::parametros.valores.sin_persona')),
                $line('tasa_venta', self::percent($data['tasa_venta'] ?? null)),
                $line('tasa_recaudo', self::percent($data['tasa_recaudo'] ?? null)),
                $line('cumplimiento_minimo', self::percent($data['cumplimiento_minimo'] ?? null)),
                $line('alcance', self::scope($data['alcance'] ?? null)),
                $line('usa_tramos', self::tranches($data['usa_tramos'] ?? null)),
            ],
            ParameterKind::Tranches => array_map(
                fn (mixed $tranche): string => is_array($tranche) ? self::tranche($tranche) : self::text($tranche),
                is_array($data['tramos'] ?? null) ? $data['tramos'] : [],
            ),
            ParameterKind::Team => [
                $line('canal', self::text($data['canal'] ?? null)),
                $line('director', self::person($data['director'] ?? null) ?? __('comisiones::comun.sin_dato')),
                $line('codigo_asociado', ($data['codigo_asociado'] ?? null) === null ? __('comisiones::parametros.valores.ninguno') : self::text($data['codigo_asociado'])),
                $line('tasa', self::percent($data['tasa'] ?? null)),
                $line('miembros', self::members($data['miembros'] ?? null)),
            ],
            ParameterKind::Derived => [
                $line('etiqueta', self::text($data['etiqueta'] ?? null)),
                $line('persona', self::person($data['persona'] ?? null) ?? __('comisiones::comun.sin_dato')),
                $line('codigo_origen', self::text($data['codigo_origen'] ?? null)),
                $line('base', self::base($data['base'] ?? null)),
                $line('tasa', self::percent($data['tasa'] ?? null)),
            ],
            ParameterKind::Options => array_map(
                fn (string $field): string => OptionFields::label($field).' ('.OptionFields::point($field).'): '.OptionFields::display($field, $data[$field] ?? null),
                array_keys(OptionFields::FIELDS),
            ),
        };
    }
}
