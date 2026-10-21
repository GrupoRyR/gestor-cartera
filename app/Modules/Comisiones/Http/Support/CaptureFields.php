<?php

namespace App\Modules\Comisiones\Http\Support;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Captures\Amount;
use App\Modules\Comisiones\Infrastructure\Captures\CodesInForce;
use App\Modules\Comisiones\Infrastructure\Captures\Supports;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * Validation rules shared by the capture forms of «Ajustes y cruces»
 * (§7.4), with their Spanish messages from resources/lang/es/ajustes.php.
 */
final class CaptureFields
{
    /**
     * The support every capture carries: pdf, jpg, png, xlsx or csv up to
     * 10 MB. The extension is checked rather than the guessed MIME type,
     * which reads a CSV as plain text; the file is only ever served back as
     * a download.
     *
     * @return list<string>
     */
    public static function support(): array
    {
        return ['required', 'file', 'extensions:'.implode(',', Supports::EXTENSIONS), 'max:'.Supports::MAX_KB];
    }

    /**
     * A month "YYYY-MM".
     *
     * @return list<string>
     */
    public static function period(): array
    {
        return ['required', 'string', 'regex:/^'.PeriodRoute::PATTERN.'$/'];
    }

    /**
     * The period of a "periodo" field, or null when it is not a month.
     */
    public static function periodOf(mixed $value): ?Period
    {
        return is_string($value) && preg_match('/^'.PeriodRoute::PATTERN.'$/', $value) === 1 ? Period::fromString($value) : null;
    }

    /**
     * A seller code with parameters in force in the period (none when the
     * period is unknown).
     *
     * @return list<mixed>
     */
    public static function code(?Period $period): array
    {
        $codes = $period === null ? [] : array_keys(app(CodesInForce::class)->options($period));

        return ['required', 'integer', self::in($codes)];
    }

    /**
     * An amount above zero, plain or Colombian.
     */
    public static function positiveAmount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $amount = Amount::parse($value);

            if ($amount === null) {
                $fail(__('comisiones::ajustes.validacion.valor_invalido'));
            } elseif (! BigDecimal::of($amount)->isPositive()) {
                $fail(__('comisiones::ajustes.validacion.valor_no_positivo'));
            }
        };
    }

    /**
     * An amount of zero or more, plain or Colombian.
     */
    public static function amount(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $amount = Amount::parse($value);

            if ($amount === null || BigDecimal::of($amount)->isNegative()) {
                $fail(__('comisiones::ajustes.validacion.valor_invalido'));
            }
        };
    }

    /**
     * A percent above zero and up to 100 ("2,5").
     */
    public static function rate(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (Amount::percent($value) === null) {
                $fail(__('comisiones::ajustes.validacion.tasa_invalida'));
            }
        };
    }

    /**
     * @param  list<int|string>  $values
     */
    private static function in(array $values): In
    {
        return Rule::in($values);
    }

    /**
     * The message of a code outside the period's codes.
     *
     * @return array<string, string>
     */
    public static function codeMessages(string $field): array
    {
        return ["{$field}.in" => __('comisiones::ajustes.validacion.codigo_no_vigente')];
    }
}
