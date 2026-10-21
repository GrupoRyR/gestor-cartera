<?php

namespace App\Modules\Comisiones\Http\Support;

use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Support\Str;

/**
 * Validation rules and readers shared by the forms of «Parámetros» (§7.2),
 * with their Spanish messages from resources/lang/es/parametros.php.
 * Percentages are typed as people write them («0,75», «0.75», «0,75 %»)
 * and stored as ParameterMapper expects them ("0.75").
 */
final class ParameterFields
{
    /** A person id or a team key: lowercase words joined by hyphens («harold-marin»). */
    public const string SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * A percent from 0 to 100 with up to six decimals, as "0.75"; null when
     * the text is not one.
     */
    public static function percent(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $text = preg_replace('/\s+|%/u', '', $value) ?? '';

        if (preg_match('/^\d{1,3}([.,]\d{1,6})?$/', $text) !== 1) {
            return null;
        }

        $percent = BigDecimal::of(str_replace(',', '.', $text));

        return $percent->isLessThanOrEqualTo(100) ? $percent->strippedOfTrailingZeros()->toString() : null;
    }

    /**
     * The rule of a percent field (0 to 100), with the message of the field
     * named by the last segment of the attribute (tramos.0.participacion →
     * participacion).
     */
    public static function percentRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::percent($value) === null) {
                $segments = explode('.', $attribute);
                $fail(__('comisiones::parametros.validacion.porcentaje.'.end($segments)));
            }
        };
    }

    /**
     * Seller codes typed as a list («14, 15 16»), in the order given; null
     * when one is not a code (a whole number of 1 or more).
     *
     * @return list<int>|null
     */
    public static function codes(mixed $value): ?array
    {
        if (! is_string($value)) {
            return null;
        }

        $codes = [];

        foreach (preg_split('/[\s,;]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            if (preg_match('/^\d{1,9}$/', $code) !== 1 || (int) $code < 1) {
                return null;
            }

            $codes[] = (int) $code;
        }

        return $codes;
    }

    /**
     * The rule of a list of seller codes: at least one, all codes, none repeated.
     */
    public static function codesRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $codes = self::codes($value);

            if ($codes === null || $codes === []) {
                $fail(__('comisiones::parametros.validacion.codigos'));

                return;
            }

            $repeated = array_keys(array_filter(array_count_values($codes), fn (int $count): bool => $count > 1));

            if ($repeated !== []) {
                $fail(__('comisiones::parametros.validacion.codigo_repetido', ['codigo' => $repeated[0]]));
            }
        };
    }

    /**
     * A stored person {id, nombre} from the name and the optional id; the id
     * is made from the name when blank («Harold Marín» → harold-marin), so
     * the codes of one person share it. Null without a name.
     *
     * @return array{id: string, nombre: string}|null
     */
    public static function person(mixed $name, mixed $id): ?array
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            return null;
        }

        $id = is_string($id) ? trim($id) : '';

        return ['id' => $id !== '' ? $id : Str::slug($name), 'nombre' => $name];
    }

    /**
     * Rules of a person id (optional, a slug).
     *
     * @return list<string>
     */
    public static function personId(): array
    {
        return ['nullable', 'string', 'max:60', 'regex:'.self::SLUG];
    }
}
