<?php

namespace App\Modules\Asesores\Http\Requests;

use App\Modules\Asesores\Domain\Format\PesosInput;
use App\Modules\Asesores\Http\Rules\PesosAmount;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The form to record a partial payment ("abono"): what the client paid, in
 * pesos written as in Colombia, and an optional note. The fields sit under
 * "abono" because the commitment page also has the edit form (valor) and
 * the fulfil form (nota): a failed abono must not fill or flag those.
 *
 * Whether the amount is below the commitment value is CompromisoService's
 * call; CommitmentActions blames its refusal on abono.valor.
 */
final class PartialPaymentRequest extends FormRequest
{
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
            'abono.valor' => ['required', 'string', 'max:40', new PesosAmount],
            // The same limit as the note of a fulfilled commitment.
            'abono.nota' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'abono.valor' => __('asesores::compromisos.campos.pagado.nombre'),
            'abono.nota' => __('asesores::compromisos.campos.nota.nombre'),
        ];
    }

    public function amount(): float
    {
        return PesosInput::parse($this->string('abono.valor')->toString()) ?? 0.0;
    }

    public function note(): ?string
    {
        $note = trim($this->string('abono.nota')->toString());

        return $note === '' ? null : $note;
    }
}
