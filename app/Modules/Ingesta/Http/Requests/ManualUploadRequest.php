<?php

namespace App\Modules\Ingesta\Http\Requests;

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use UnexpectedValueException;

/**
 * POST /ingesta/carga-manual: the company, the cutoff date (not after
 * today) and the .xlsx file (up to 20 MB). The route already checked
 * ingesta.upload.
 */
final class ManualUploadRequest extends FormRequest
{
    public const int MAX_KILOBYTES = 20 * 1024;

    /**
     * The companies the user may upload for: those of CurrentCompany when
     * Core binds it, else every company.
     *
     * @return list<CompanyCode>
     */
    public static function companies(): array
    {
        if (! app()->bound(CurrentCompany::class)) {
            return CompanyCode::cases();
        }

        return array_map(fn (Company $company): CompanyCode => $company->code, app(CurrentCompany::class)->available());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'empresa' => ['required', 'string', Rule::in(array_map(fn (CompanyCode $code): string => $code->value, self::companies()))],
            'fecha_corte' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::now()->format('Y-m-d')],
            'archivo' => ['required', 'file', 'extensions:xlsx', 'max:'.self::MAX_KILOBYTES],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('ingesta::manual.atributos');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha_corte.before_or_equal' => __('ingesta::manual.validacion.corte_futuro'),
            'archivo.extensions' => __('ingesta::manual.validacion.formato'),
            'archivo.max' => __('ingesta::manual.validacion.peso'),
        ];
    }

    public function company(): CompanyCode
    {
        return CompanyCode::from($this->string('empresa')->toString());
    }

    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('fecha_corte')->toString(), 'America/Bogota')
            ?: throw new UnexpectedValueException('The cutoff date passed date_format but did not parse.');
    }

    public function workbook(): UploadedFile
    {
        $file = $this->file('archivo');

        return $file instanceof UploadedFile ? $file : throw new UnexpectedValueException('The validated file is missing.');
    }
}
