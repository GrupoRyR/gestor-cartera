<?php

namespace App\Modules\Bancos\Http\Requests;

use App\Modules\Bancos\Domain\Statement\Cells;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/recaudo?desde=AAAA-MM-DD&hasta=AAAA-MM-DD[&empresa=SGX|SDC]: the
 * range of days (both ends included, up to a year) and, optionally, one
 * company.
 */
final class CollectionRangeRequest extends FormRequest
{
    public const int MAX_DAYS = 366;

    /**
     * The token already authorized the request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:desde',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $from = $this->date('desde');
                    $to = $this->date('hasta');

                    if ($from !== null && $to !== null && $from->diffInDays($to) >= self::MAX_DAYS) {
                        $fail(__('bancos::recaudo.api.rango_largo', ['dias' => self::MAX_DAYS]));
                    }
                },
            ],
            'empresa' => ['nullable', 'string', Rule::enum(CompanyCode::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'desde.date_format' => __('bancos::recaudo.api.formato_fecha'),
            'hasta.date_format' => __('bancos::recaudo.api.formato_fecha'),
            'hasta.after_or_equal' => __('bancos::recaudo.api.hasta_antes'),
        ];
    }

    public function from(): CarbonImmutable
    {
        return $this->day('desde');
    }

    public function to(): CarbonImmutable
    {
        return $this->day('hasta');
    }

    public function company(): ?CompanyCode
    {
        $company = $this->string('empresa')->toString();

        return $company === '' ? null : CompanyCode::from($company);
    }

    private function day(string $key): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string($key)->toString(), Cells::TIMEZONE)
            ?: CarbonImmutable::now(Cells::TIMEZONE)->startOfDay();
    }
}
