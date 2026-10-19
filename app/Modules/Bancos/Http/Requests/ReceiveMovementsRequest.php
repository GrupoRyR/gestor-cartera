<?php

namespace App\Modules\Bancos\Http\Requests;

use App\Modules\Bancos\Domain\Movement\MovementType;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/movimientos: the body Treasury's app sends.
 *
 *     {
 *       "empresa": "SGX",                     SGX | SDC
 *       "banco": "bogota",                    bogota | davivienda | bancolombia | miami
 *       "referencia_lote": "extracto-15-oct", optional, shown in the load history
 *       "movimientos": [{
 *         "fecha": "2026-10-15",              Y-m-d
 *         "tipo": "credito",                  credito (default) | debito; debits are ignored
 *         "valor": "1250000.00",              positive, a dot for decimals, no thousands separator
 *         "descripcion": "…", "referencia": "…", "cuenta": "…",   optional
 *         "moneda": "COP"                     optional ISO code; the account's by default
 *       }]
 *     }
 *
 * It is the normalized form every file reader produces (StatementMovement),
 * so the app of Cartera does not change when Treasury switches from files to
 * the API.
 */
final class ReceiveMovementsRequest extends FormRequest
{
    /** Movements per request; more than a month of a busy account. */
    public const int MAX_MOVEMENTS = 5000;

    /**
     * The token already authorized the request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa' => ['required', 'string', Rule::enum(CompanyCode::class)],
            'banco' => ['required', 'string', Rule::enum(Bank::class)],
            'referencia_lote' => ['nullable', 'string', 'max:255'],
            'movimientos' => ['required', 'array', 'min:1', 'max:'.self::MAX_MOVEMENTS],
            'movimientos.*.fecha' => ['required', 'date_format:Y-m-d'],
            'movimientos.*.tipo' => ['nullable', 'string', Rule::enum(MovementType::class)],
            'movimientos.*.valor' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'movimientos.*.descripcion' => ['nullable', 'string', 'max:500'],
            'movimientos.*.referencia' => ['nullable', 'string', 'max:100'],
            'movimientos.*.cuenta' => ['nullable', 'string', 'max:50'],
            'movimientos.*.moneda' => ['nullable', 'string', 'size:3', 'alpha'],
        ];
    }

    /**
     * Amounts may arrive as JSON numbers; the rules compare them as text.
     */
    protected function prepareForValidation(): void
    {
        $movements = $this->input('movimientos');

        if (! is_array($movements)) {
            return;
        }

        foreach ($movements as $index => $movement) {
            if (is_array($movement) && (is_int($movement['valor'] ?? null) || is_float($movement['valor'] ?? null))) {
                $movements[$index]['valor'] = rtrim(rtrim(number_format((float) $movement['valor'], 2, '.', ''), '0'), '.');
            }
        }

        $this->merge(['movimientos' => $movements]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'movimientos.*.valor.regex' => 'El valor debe ser positivo, con punto para los decimales (máximo 2) y sin separador de miles.',
            'movimientos.*.valor.not_regex' => 'El valor debe ser mayor que cero.',
            'movimientos.*.fecha.date_format' => 'La fecha debe escribirse como año-mes-día, por ejemplo 2026-10-15.',
        ];
    }

    public function company(): CompanyCode
    {
        return CompanyCode::from($this->string('empresa')->toString());
    }

    public function bank(): Bank
    {
        return Bank::from($this->string('banco')->toString());
    }

    public function batchLabel(): ?string
    {
        $label = $this->string('referencia_lote')->trim()->toString();

        return $label === '' ? null : $label;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function movements(): array
    {
        /** @var list<array<string, mixed>> $movements */
        $movements = array_values((array) $this->validated('movimientos'));

        return $movements;
    }
}
