<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Infrastructure\Files\TabularFile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * POST /comisiones/presupuesto/{periodo}/carga: the budget template, .csv
 * or .xlsx. Its rows are checked by BudgetFile.
 */
final class ImportBudgetRequest extends FormRequest
{
    /** Kilobytes; a budget of every code is a few. */
    public const int MAX_KB = 2048;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'extensions:'.implode(',', TabularFile::EXTENSIONS), 'max:'.self::MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'archivo' => __('comisiones::presupuesto.validacion.archivo'),
        ];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('archivo');
        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }
}
