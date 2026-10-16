<?php

namespace App\Modules\Asesores\Http\Requests;

use App\Modules\Asesores\Domain\Format\PesosInput;
use App\Modules\Asesores\Http\Rules\PesosAmount;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The form to promise a payment, to create a commitment or to edit a pending
 * one: a date (today or later), an amount in pesos and optional notes.
 *
 * Who may write is not decided here: the route needs asesores.manage and the
 * controller asks the policy, after finding the client or commitment inside
 * the user's scope.
 */
final class SaveCommitmentRequest extends FormRequest
{
    private const int MAX_NOTES = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'valor' => ['required', 'string', 'max:40', new PesosAmount],
            'notas' => ['nullable', 'string', 'max:'.self::MAX_NOTES],
            // Which form failed, so the page can open its dialog again.
            'formulario' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fecha' => __('asesores::compromisos.campos.fecha.nombre'),
            'valor' => __('asesores::compromisos.campos.valor.nombre'),
            'notas' => __('asesores::compromisos.campos.notas.nombre'),
        ];
    }

    /**
     * The day they promised to pay, from the start of that day in Bogotá.
     */
    public function dueDate(): CarbonImmutable
    {
        // Already checked as Y-m-d by the rules.
        return CarbonImmutable::parse($this->string('fecha')->toString(), 'America/Bogota')->startOfDay();
    }

    public function amount(): float
    {
        return PesosInput::parse($this->string('valor')->toString()) ?? 0.0;
    }

    public function notes(): ?string
    {
        $notes = trim($this->string('notas')->toString());

        return $notes === '' ? null : $notes;
    }
}
