<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\CaptureFields;
use App\Modules\Comisiones\Infrastructure\Captures\Amount;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * POST /comisiones/ajustes/cruces: a manual crossing (§5.4), the backup of
 * Report 4 with the fields the spec lists: seller, crossing type, invoice
 * and its date, crossing date, client code and name, the third party
 * crossed, the invoice amount and the crossed amount with VAT. The base
 * without VAT comes from the invoice VAT (CrossingResolver) or, without
 * it, from the crossed amount without VAT the user types.
 *
 * Its period is the month of the crossing date; the seller must have
 * parameters in force in that month.
 */
final class StoreManualCrossingRequest extends FormRequest
{
    public const array TYPES = ['anticipo', 'cliente-proveedor', 'otro'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'vendedor' => CaptureFields::code($this->crossingPeriod()),
            'tipo_cruce' => ['required', 'string', 'in:'.implode(',', self::TYPES)],
            'factura' => ['required', 'string', 'max:50'],
            'fecha_factura' => ['required', 'date_format:Y-m-d'],
            'fecha_cruce' => ['required', 'date_format:Y-m-d'],
            'codigo_cliente' => ['required', 'string', 'max:50'],
            'razon_social' => ['required', 'string', 'max:255'],
            'tercero' => ['nullable', 'string', 'max:50'],
            'importe_original' => ['required', 'string', 'max:30', CaptureFields::positiveAmount()],
            'valor_cruzado' => ['required', 'string', 'max:30', CaptureFields::positiveAmount()],
            'iva_factura' => ['nullable', 'string', 'max:30', CaptureFields::amount()],
            'valor_sin_iva' => ['nullable', 'string', 'max:30', CaptureFields::positiveAmount()],
            'soporte' => CaptureFields::support(),
        ];
    }

    /**
     * Without the invoice VAT the amount without VAT is required, and the
     * VAT cannot exceed the invoice amount.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $vat = Amount::parse($this->input('iva_factura'));
                $invoice = Amount::parse($this->input('importe_original'));

                if (self::blank($this->input('iva_factura')) && self::blank($this->input('valor_sin_iva'))) {
                    $validator->errors()->add('valor_sin_iva', __('comisiones::ajustes.validacion.sin_iva_requerido'));
                }

                if ($vat !== null && $invoice !== null && BigDecimal::of($vat)->isGreaterThan($invoice)) {
                    $validator->errors()->add('iva_factura', __('comisiones::ajustes.validacion.iva_mayor'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CaptureFields::codeMessages('vendedor');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (['vendedor', 'tipo_cruce', 'factura', 'fecha_factura', 'fecha_cruce', 'codigo_cliente', 'razon_social', 'tercero', 'importe_original', 'valor_cruzado', 'iva_factura', 'valor_sin_iva', 'soporte'] as $field) {
            $attributes[$field] = __('comisiones::ajustes.validacion.'.$field);
        }

        return $attributes;
    }

    /** The month of the crossing date. */
    public function period(): Period
    {
        return Period::fromString(substr((string) $this->validated('fecha_cruce'), 0, 7));
    }

    /**
     * The columns of comisiones_cruces; ManualCaptures adds the key, the
     * origin and the SAP status.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $vat = $this->validated('iva_factura');
        $withoutVat = $this->validated('valor_sin_iva');
        $third = $this->validated('tercero');

        return [
            'slp_code_capturado' => (int) $this->validated('vendedor'),
            'tipo_cruce' => (string) $this->validated('tipo_cruce'),
            'numero_factura' => trim((string) $this->validated('factura')),
            'fecha_factura' => (string) $this->validated('fecha_factura'),
            'fecha_cruce' => (string) $this->validated('fecha_cruce'),
            'codigo_cliente' => trim((string) $this->validated('codigo_cliente')),
            'razon_social' => trim((string) $this->validated('razon_social')),
            'codigo_proveedor' => self::blank($third) ? null : trim((string) $third),
            'valor_factura' => (string) Amount::parse($this->validated('importe_original')),
            'valor_compensado' => (string) Amount::parse($this->validated('valor_cruzado')),
            'iva_factura' => self::blank($vat) ? null : Amount::parse($vat),
            'valor_compensado_sin_iva' => self::blank($withoutVat) ? null : Amount::parse($withoutVat),
        ];
    }

    public function support(): UploadedFile
    {
        $file = $this->file('soporte');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /** The month of the typed crossing date, or null while it is not a date. */
    private function crossingPeriod(): ?Period
    {
        $value = $this->input('fecha_cruce');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        [$year, $month, $day] = array_map(intval(...), explode('-', $value));

        return checkdate($month, $day, $year) ? Period::of($year, $month) : null;
    }

    private static function blank(mixed $value): bool
    {
        return ! is_string($value) || trim($value) === '';
    }
}
