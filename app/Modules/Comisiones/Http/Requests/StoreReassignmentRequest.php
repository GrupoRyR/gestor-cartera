<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\CaptureFields;
use App\Modules\Comisiones\Infrastructure\Captures\CrossingChoices;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * POST /comisiones/ajustes/reasignaciones: moves a crossing of the period
 * (§5.4) to another seller, with its reason and support. The crossing must
 * be one CrossingChoices offers (SAP or approved manual, dated in the
 * period, not annulled), and the new seller a code in force other than the
 * one it goes to today.
 */
final class StoreReassignmentRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $period = CaptureFields::periodOf($this->input('periodo'));

        return [
            'periodo' => CaptureFields::period(),
            'clave_cruce' => ['required', 'string', 'max:80', self::offered($period)],
            'codigo' => CaptureFields::code($period),
            'motivo' => ['required', 'string', 'max:500'],
            'soporte' => CaptureFields::support(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['clave_cruce', 'codigo'])) {
                    return;
                }

                $crossing = CrossingRecord::query()->where('clave', (string) $this->input('clave_cruce'))->first();
                $current = $crossing === null ? null : (app(CrossingChoices::class)->sellers([$crossing])[$crossing->clave] ?? null);

                if ($current !== null && $current === (int) $this->input('codigo')) {
                    $validator->errors()->add('codigo', __('comisiones::ajustes.validacion.mismo_vendedor', ['codigo' => $current]));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return CaptureFields::codeMessages('codigo');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'periodo' => __('comisiones::ajustes.validacion.periodo'),
            'clave_cruce' => __('comisiones::ajustes.validacion.clave_cruce'),
            'codigo' => __('comisiones::ajustes.validacion.codigo'),
            'motivo' => __('comisiones::ajustes.validacion.motivo'),
            'soporte' => __('comisiones::ajustes.validacion.soporte'),
        ];
    }

    public function period(): Period
    {
        return Period::fromString((string) $this->validated('periodo'));
    }

    /**
     * The columns of comisiones_reasignaciones.
     *
     * @return array{clave_cruce: string, slp_code: int, motivo: string}
     */
    public function columns(): array
    {
        return [
            'clave_cruce' => (string) $this->validated('clave_cruce'),
            'slp_code' => (int) $this->validated('codigo'),
            'motivo' => trim((string) $this->validated('motivo')),
        ];
    }

    public function support(): UploadedFile
    {
        $file = $this->file('soporte');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    /**
     * A crossing the period offers for reassignment.
     */
    private static function offered(?Period $period): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($period): void {
            $offered = $period !== null && is_string($value)
                && app(CrossingChoices::class)->query($period)->where('clave', $value)->exists();

            if (! $offered) {
                $fail(__('comisiones::ajustes.validacion.cruce_no_disponible'));
            }
        };
    }
}
