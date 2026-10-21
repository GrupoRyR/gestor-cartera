<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Infrastructure\Captures\Amount;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /comisiones/presupuesto/{periodo}/{codigo}: the budget and channel of
 * one row of the table, sent as filas[{codigo}][valor] and
 * filas[{codigo}][canal] so an error shows under its own row. The budget
 * takes a plain decimal or the Colombian format.
 */
final class UpdateBudgetRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $row = 'filas.'.$this->code();

        return [
            "{$row}.valor" => ['required', 'string', 'max:30', self::amountRule()],
            "{$row}.canal" => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $row = 'filas.'.$this->code();

        return [
            "{$row}.valor" => __('comisiones::presupuesto.validacion.valor'),
            "{$row}.canal" => __('comisiones::presupuesto.validacion.canal'),
        ];
    }

    public function code(): int
    {
        return (int) $this->route('codigo');
    }

    /** The budget as "123.45". */
    public function amount(): string
    {
        return (string) Amount::parse($this->validated('filas.'.$this->code().'.valor'));
    }

    public function channel(): ?string
    {
        $channel = $this->validated('filas.'.$this->code().'.canal');

        return is_string($channel) && trim($channel) !== '' ? trim($channel) : null;
    }

    /**
     * A positive amount, plain or Colombian.
     */
    public static function amountRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $amount = Amount::parse($value);

            if ($amount === null) {
                $fail(__('comisiones::presupuesto.validacion.valor_invalido'));
            } elseif (! BigDecimal::of($amount)->isPositive()) {
                $fail(__('comisiones::presupuesto.validacion.valor_no_positivo'));
            }
        };
    }
}
