<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Domain\Input\AdjustmentKind;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\CaptureFields;
use App\Modules\Comisiones\Infrastructure\Captures\Amount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /comisiones/ajustes/reintegros: a reimbursement (adds) or a
 * deduction (subtracts) of a code in force in the period (§5.5), with its
 * amount above zero, description and support.
 */
final class StoreAdjustmentRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'periodo' => CaptureFields::period(),
            'codigo' => CaptureFields::code(CaptureFields::periodOf($this->input('periodo'))),
            'tipo' => ['required', 'string', Rule::enum(AdjustmentKind::class)],
            'valor' => ['required', 'string', 'max:30', CaptureFields::positiveAmount()],
            'descripcion' => ['required', 'string', 'max:500'],
            'soporte' => CaptureFields::support(),
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
            'codigo' => __('comisiones::ajustes.validacion.codigo'),
            'tipo' => __('comisiones::ajustes.validacion.tipo'),
            'valor' => __('comisiones::ajustes.validacion.valor'),
            'descripcion' => __('comisiones::ajustes.validacion.descripcion'),
            'soporte' => __('comisiones::ajustes.validacion.soporte'),
        ];
    }

    public function period(): Period
    {
        return Period::fromString((string) $this->validated('periodo'));
    }

    /**
     * The columns of comisiones_ajustes.
     *
     * @return array{periodo: string, slp_code: int, tipo: string, valor: string, descripcion: string}
     */
    public function columns(): array
    {
        return [
            'periodo' => $this->period()->key(),
            'slp_code' => (int) $this->validated('codigo'),
            'tipo' => (string) $this->validated('tipo'),
            'valor' => (string) Amount::parse($this->validated('valor')),
            'descripcion' => trim((string) $this->validated('descripcion')),
        ];
    }

    public function support(): UploadedFile
    {
        $file = $this->file('soporte');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }
}
