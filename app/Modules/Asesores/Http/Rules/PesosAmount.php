<?php

namespace App\Modules\Asesores\Http\Rules;

use App\Modules\Asesores\Domain\Format\PesosInput;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount in pesos typed in a form: greater than zero and within what a
 * commitment can hold.
 */
final class PesosAmount implements ValidationRule
{
    /**
     * Well above any invoice of the group, well below what the column holds.
     */
    public const float MAX = 999_999_999_999.99;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $amount = is_string($value) ? PesosInput::parse($value) : null;

        if ($amount === null || $amount <= 0 || $amount > self::MAX) {
            $fail('asesores::compromisos.errores.valor')->translate();
        }
    }
}
