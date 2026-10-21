<?php

namespace App\Modules\Bancos\Http\Requests;

use App\Modules\Bancos\Application\Access\VisibleCompanies;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * POST /bancos/cargas: the company the account belongs to, the bank, and the
 * statement (.xlsx, .xls, .csv or .txt, up to 10 MB). The route already checked
 * bancos.import.
 */
final class UploadStatementRequest extends FormRequest
{
    public const int MAX_KILOBYTES = 10 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companies = array_map(fn (CompanyCode $code): string => $code->value, app(VisibleCompanies::class)->available());

        return [
            'empresa' => ['required', 'string', Rule::in($companies)],
            'banco' => ['required', 'string', Rule::enum(Bank::class)],
            'archivo' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('bancos::cargas.atributos');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.extensions' => __('bancos::cargas.validacion.formato'),
            'archivo.max' => __('bancos::cargas.validacion.peso'),
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

    public function statement(): UploadedFile
    {
        $file = $this->file('archivo');

        assert($file instanceof UploadedFile);

        return $file;
    }
}
