<?php

namespace App\Modules\Bancos\Http\Requests;

use App\Modules\Bancos\Application\Movements\MovementFilter;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Domain\Statement\Cells;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /bancos/movimientos/{banco}: the filters (date range, amount range,
 * status, text) and the order. The route already checked bancos.view.
 */
final class MovementFilterRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
            'valor_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'valor_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999', Rule::when($this->filled('valor_min'), 'gte:valor_min')],
            'estado' => ['nullable', 'string', Rule::enum(MovementStatus::class)],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(MovementFilter::SORTS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return __('bancos::movimientos.atributos');
    }

    public function filter(): MovementFilter
    {
        $status = $this->string('estado')->toString();
        $text = trim($this->string('q')->toString());

        return new MovementFilter(
            from: $this->day('desde'),
            to: $this->day('hasta'),
            minAmount: $this->amount('valor_min'),
            maxAmount: $this->amount('valor_max'),
            status: $status === '' ? null : MovementStatus::from($status),
            text: $text === '' ? null : $text,
            sort: $this->string('sort', 'date')->toString(),
            direction: $this->string('direction', 'desc')->toString(),
        );
    }

    private function day(string $key): ?CarbonImmutable
    {
        $value = $this->string($key)->toString();

        return $value === '' ? null : (CarbonImmutable::createFromFormat('!Y-m-d', $value, Cells::TIMEZONE) ?: null);
    }

    private function amount(string $key): ?string
    {
        $value = $this->string($key)->toString();

        return $value === '' ? null : $value;
    }
}
