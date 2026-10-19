<?php

namespace App\Modules\Cobro\Http\Requests;

use App\Modules\Cobro\Domain\Templates\Placeholders;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /cobro/plantillas/{empresa}: subject (asunto), opening text (inicio)
 * and closing text (cierre). Only the placeholders of Placeholders::ALLOWED
 * may appear.
 */
final class UpdateTemplateRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        $known = function (string $attribute, mixed $value, Closure $fail): void {
            $unknown = Placeholders::unknown((string) $value);

            if ($unknown !== []) {
                $fail(__('cobro::plantillas.mensajes.desconocidas', [
                    'variables' => implode(', ', array_map(fn (string $name): string => '{'.$name.'}', $unknown)),
                ]));
            }
        };

        return [
            'asunto' => ['required', 'string', 'max:200', $known],
            'inicio' => ['required', 'string', 'max:5000', $known],
            'cierre' => ['required', 'string', 'max:5000', $known],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'asunto' => __('cobro::plantillas.validacion.asunto'),
            'inicio' => __('cobro::plantillas.validacion.inicio'),
            'cierre' => __('cobro::plantillas.validacion.cierre'),
        ];
    }

    public function content(): TemplateContent
    {
        $normalize = fn (string $key): string => trim(str_replace(["\r\n", "\r"], "\n", (string) $this->validated($key)));

        return new TemplateContent($normalize('asunto'), $normalize('inicio'), $normalize('cierre'));
    }
}
