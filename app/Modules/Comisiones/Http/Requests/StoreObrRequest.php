<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Support\CaptureFields;
use App\Modules\Comisiones\Infrastructure\Captures\Amount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /comisiones/ajustes/obr: an OBR/Escudos sale (§5.5) of a code in
 * force in the period, with its description, amount sold, rate (percent)
 * and support.
 */
final class StoreObrRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'periodo' => CaptureFields::period(),
            'codigo' => CaptureFields::code(CaptureFields::periodOf($this->input('periodo'))),
            'descripcion' => ['required', 'string', 'max:500'],
            'valor_vendido' => ['required', 'string', 'max:30', CaptureFields::positiveAmount()],
            'tasa' => ['required', 'string', 'max:10', CaptureFields::rate()],
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
            'descripcion' => __('comisiones::ajustes.validacion.descripcion'),
            'valor_vendido' => __('comisiones::ajustes.validacion.valor_vendido'),
            'tasa' => __('comisiones::ajustes.validacion.tasa'),
            'soporte' => __('comisiones::ajustes.validacion.soporte'),
        ];
    }

    public function period(): Period
    {
        return Period::fromString((string) $this->validated('periodo'));
    }

    /**
     * The columns of comisiones_obr.
     *
     * @return array{periodo: string, slp_code: int, descripcion: string, valor_vendido: string, tasa: string}
     */
    public function columns(): array
    {
        return [
            'periodo' => $this->period()->key(),
            'slp_code' => (int) $this->validated('codigo'),
            'descripcion' => trim((string) $this->validated('descripcion')),
            'valor_vendido' => (string) Amount::parse($this->validated('valor_vendido')),
            'tasa' => (string) Amount::percent($this->validated('tasa')),
        ];
    }

    public function support(): UploadedFile
    {
        $file = $this->file('soporte');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }
}
