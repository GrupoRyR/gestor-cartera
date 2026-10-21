<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Http\Support\ParameterFields;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Validation\Validator;

/**
 * POST /comisiones/parametros/tramos: a version of the collection tranche
 * table (§4 "TramoRecaudo", §5.3) as rows tramos[i][desde|hasta|participacion].
 * The form offers a few empty rows to add tranches without JavaScript; a
 * row left empty is dropped. A blank «hasta» is the open-ended last tranche.
 * Gaps and overlaps are refused by the domain (TrancheTable).
 */
final class StoreTrancheVersionRequest extends ParameterVersionRequest
{
    /** Rows the form may send, the empty ones included. */
    public const int MAX_ROWS = 30;

    public function kind(): ParameterKind
    {
        return ParameterKind::Tranches;
    }

    /**
     * @return array<string, list<mixed>>
     */
    protected function fieldRules(): array
    {
        return [
            'tramos' => ['required', 'array', 'max:'.self::MAX_ROWS],
            'tramos.*' => ['array'],
            'tramos.*.desde' => ['nullable', 'required_with:tramos.*.hasta,tramos.*.participacion', 'integer', 'min:0', 'max:100000'],
            'tramos.*.hasta' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'tramos.*.participacion' => ['nullable', 'required_with:tramos.*.desde,tramos.*.hasta', ParameterFields::percentRule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'tramos.required' => __('comisiones::parametros.validacion.sin_tramos'),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('tramos') && $this->filledRows() === []) {
                    $validator->errors()->add('tramos', __('comisiones::parametros.validacion.sin_tramos'));
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function versionData(): array
    {
        return ['tramos' => array_map(fn (array $row): array => [
            'desde' => (int) $row['desde'],
            'hasta' => self::blank($row['hasta'] ?? null) ? null : (int) $row['hasta'],
            'participacion' => ParameterFields::percent($row['participacion'] ?? null) ?? '0',
        ], $this->filledRows())];
    }

    /**
     * The rows with a first day, in the order typed.
     *
     * @return list<array<string, mixed>>
     */
    private function filledRows(): array
    {
        $rows = $this->input('tramos');

        return array_values(array_filter(
            is_array($rows) ? array_filter($rows, 'is_array') : [],
            fn (array $row): bool => ! self::blank($row['desde'] ?? null),
        ));
    }

    private static function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
