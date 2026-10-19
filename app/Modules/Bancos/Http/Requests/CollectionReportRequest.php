<?php

namespace App\Modules\Bancos\Http\Requests;

use App\Modules\Bancos\Application\Reports\CollectionReports;
use App\Modules\Bancos\Domain\Statement\Cells;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /bancos/recaudo?fecha=AAAA-MM-DD: the day the report is about (not after
 * today; yesterday by default). The route already checked bancos.view.
 */
final class CollectionReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:2000-01-01'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['fecha' => __('bancos::recaudo.web.fecha_atributo')];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha.before_or_equal' => __('bancos::recaudo.web.fecha_futura'),
        ];
    }

    public function day(): CarbonImmutable
    {
        $value = $this->string('fecha')->toString();

        if ($value === '') {
            return CollectionReports::defaultDay();
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, Cells::TIMEZONE) ?: CollectionReports::defaultDay();
    }
}
