<?php

namespace App\Modules\Cobro\Http\Requests;

use App\Modules\Cobro\Domain\Copies\CopyRecipients;
use App\Modules\Cobro\Domain\Copies\CopySettings;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /cobro/copias/{empresa}: the fixed addresses (fijos, one per line or
 * comma-separated), whether the client's advisor is copied (asesor) and
 * the manager rows (gerentes[n][grupo], gerentes[n][correos]). Blank rows
 * are ignored; a row needs both its group and its addresses, and every
 * address must be valid, with a dot in its domain (ana@segurex is not).
 */
final class UpdateCopiesRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        $addresses = function (string $attribute, mixed $value, Closure $fail): void {
            $invalid = array_values(array_filter(
                CopyRecipients::split((string) $value),
                fn (string $address): bool => ! str_contains((string) strrchr($address, '@'), '.')
                    || validator(['correo' => $address], ['correo' => ['email']])->fails(),
            ));

            if ($invalid !== []) {
                $fail(__('cobro::copias.validacion.invalidos', ['correos' => implode(', ', $invalid)]));
            }
        };

        return [
            'fijos' => ['nullable', 'string', 'max:5000', $addresses],
            'asesor' => ['nullable', 'boolean'],
            'gerentes' => ['nullable', 'array', 'max:50'],
            'gerentes.*' => ['array'],
            'gerentes.*.grupo' => ['nullable', 'string', 'max:100', 'required_with:gerentes.*.correos'],
            'gerentes.*.correos' => ['nullable', 'string', 'max:2000', 'required_with:gerentes.*.grupo', $addresses],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'gerentes.*.grupo.required_with' => __('cobro::copias.validacion.grupo_obligatorio'),
            'gerentes.*.correos.required_with' => __('cobro::copias.validacion.correos_obligatorios'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fijos' => __('cobro::copias.validacion.fijos'),
            'gerentes.*.grupo' => __('cobro::copias.validacion.grupo'),
            'gerentes.*.correos' => __('cobro::copias.validacion.correos'),
        ];
    }

    public function settings(): CopySettings
    {
        $managers = [];

        foreach ((array) $this->validated('gerentes', []) as $row) {
            $group = trim((string) preg_replace('/\s+/u', ' ', (string) ($row['grupo'] ?? '')));
            $emails = CopyRecipients::split((string) ($row['correos'] ?? ''));

            if ($group !== '' && $emails !== []) {
                $managers[] = ['group' => $group, 'emails' => $emails];
            }
        }

        return new CopySettings(
            fixed: CopyRecipients::split((string) $this->validated('fijos', '')),
            advisor: $this->boolean('asesor'),
            managers: $managers,
        );
    }
}
