<?php

namespace App\Modules\Cartera\Http\Requests;

use App\Modules\Cartera\Application\Calls\NewCall;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /cartera/clientes/{client}/llamadas: the shape of a call, with its
 * fields nested under "llamada" so the form shares the client file with the
 * other forms. LogCall checks the rest (permissions, dates, agreement and
 * invoices) and its errors come back under the same fields.
 *
 * The number is picked from the client's contacts (numero) or typed
 * (telefono), and so is the person (persona or contacto): a typed one wins,
 * so the form works without any script.
 */
final class StoreCallRequest extends FormRequest
{
    private const string TIMEZONE = 'America/Bogota';

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $phone = ['nullable', 'string', 'max:'.NewCall::PHONE_MAX_LENGTH];
        $contact = ['nullable', 'string', 'max:'.NewCall::CONTACT_MAX_LENGTH];

        return [
            'llamada' => ['required', 'array'],
            'llamada.ocurrio' => ['nullable', 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s'],
            'llamada.canal' => ['required', Rule::enum(CallChannel::class)],
            'llamada.numero' => $phone,
            'llamada.telefono' => [...$phone, 'required_without:llamada.numero'],
            'llamada.persona' => $contact,
            'llamada.contacto' => $contact,
            'llamada.resultado' => ['required', Rule::enum(CallOutcome::class)],
            'llamada.notas' => ['nullable', 'string', 'max:2000'],
            'llamada.acuerdo' => ['required', Rule::enum(CallAgreement::class)],
            'llamada.seguimiento' => ['nullable', 'date_format:Y-m-d'],
            'llamada.facturas' => ['nullable', 'array', 'max:500'],
            'llamada.facturas.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'llamada.ocurrio' => 'fecha y hora',
            'llamada.canal' => 'canal',
            'llamada.numero' => 'número',
            'llamada.telefono' => 'otro número',
            'llamada.persona' => 'con quién hablaste',
            'llamada.contacto' => 'otra persona',
            'llamada.resultado' => 'resultado',
            'llamada.notas' => 'notas',
            'llamada.acuerdo' => 'acuerdo',
            'llamada.seguimiento' => 'día para volver a llamar',
            'llamada.facturas' => 'facturas',
            'llamada.facturas.*' => 'factura',
        ];
    }

    /**
     * The validated form as LogCall's input.
     */
    public function toNewCall(int $clientId): NewCall
    {
        $data = $this->validated()['llamada'];

        return new NewCall(
            clientId: $clientId,
            channel: CallChannel::from($data['canal']),
            phone: (string) $this->typedOrPicked($data, 'telefono', 'numero'),
            contactName: $this->typedOrPicked($data, 'contacto', 'persona'),
            outcome: CallOutcome::from($data['resultado']),
            notes: $data['notas'] ?? null,
            agreement: CallAgreement::from($data['acuerdo']),
            followUpOn: isset($data['seguimiento'])
                ? CarbonImmutable::createFromFormat('!Y-m-d', $data['seguimiento'], self::TIMEZONE) ?: null
                : null,
            occurredAt: isset($data['ocurrio']) ? CarbonImmutable::parse($data['ocurrio'], self::TIMEZONE) : null,
            invoiceIds: array_values(array_map(intval(...), $data['facturas'] ?? [])),
        );
    }

    /**
     * A form that fails goes back to the client file, whichever page sent it.
     */
    protected function getRedirectUrl(): string
    {
        return route('cartera.clientes.show', (int) $this->route('client'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function typedOrPicked(array $data, string $typed, string $picked): ?string
    {
        foreach ([$typed, $picked] as $field) {
            $value = trim((string) ($data[$field] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
