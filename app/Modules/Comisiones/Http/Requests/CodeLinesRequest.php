<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Http\Support\LineFilters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /comisiones/periodos/{periodo}/codigos/{codigo}: the line filters
 * cliente, factura, desde and hasta ("Y-m-d"), all optional, and ?version=.
 */
final class CodeLinesRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'cliente' => ['nullable', 'string', 'max:100'],
            'factura' => ['nullable', 'string', 'max:50'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'cliente' => __('comisiones::codigo.filtros.cliente'),
            'factura' => __('comisiones::codigo.filtros.factura'),
            'desde' => __('comisiones::codigo.filtros.desde'),
            'hasta' => __('comisiones::codigo.filtros.hasta'),
        ];
    }

    public function filters(): LineFilters
    {
        return new LineFilters(
            client: self::text($this->validated('cliente')),
            invoice: self::text($this->validated('factura')),
            from: self::text($this->validated('desde')),
            to: self::text($this->validated('hasta')),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
