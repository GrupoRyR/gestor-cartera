<?php

namespace App\Modules\Comisiones\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /comisiones/periodos/{periodo}/aprobar: the revision the approver
 * reviewed (revision) and one justification per blocking alert
 * (justificaciones[i][clave] = Alert::key(), justificaciones[i][texto]).
 *
 * The alert key travels as a value, not as an array key: keys such as
 * "comision_negativa:81.1" carry dots, which the validator would read as
 * nesting.
 */
final class ApproveSettlementRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'justificaciones' => ['nullable', 'array', 'max:500'],
            'justificaciones.*.clave' => ['required', 'string', 'max:300'],
            'justificaciones.*.texto' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'revision' => __('comisiones::liquidacion.validacion.revision'),
            'justificaciones' => __('comisiones::liquidacion.validacion.justificaciones'),
        ];
    }

    public function reviewedRevision(): int
    {
        return (int) $this->validated('revision');
    }

    /**
     * Alert key => justification, without the blank ones: a blank
     * justification is a missing one, which the approval reports.
     *
     * @return array<string, string>
     */
    public function justifications(): array
    {
        $justifications = [];

        foreach ((array) $this->validated('justificaciones', []) as $row) {
            $key = is_array($row) ? trim((string) ($row['clave'] ?? '')) : '';
            $text = is_array($row) ? trim((string) ($row['texto'] ?? '')) : '';

            if ($key !== '' && $text !== '') {
                $justifications[$key] = $text;
            }
        }

        return $justifications;
    }

    /**
     * The form row of each alert key, to put an error under its field.
     *
     * @return array<string, int|string>
     */
    public function rowsByKey(): array
    {
        $rows = [];

        foreach ((array) $this->input('justificaciones', []) as $index => $row) {
            if (is_array($row) && is_string($row['clave'] ?? null)) {
                $rows[trim($row['clave'])] = $index;
            }
        }

        return $rows;
    }
}
