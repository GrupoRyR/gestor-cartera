<?php

namespace App\Modules\Cartera\Http\Requests;

use App\Modules\Core\Contracts\Enums\ClientStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /cartera/clientes/{client}/estado: the new status of a client, chosen
 * on the Kanban board or in the client file. The permission to change a
 * status is checked by the route and by Core's ClientStatusService.
 */
final class MoveClientRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::enum(ClientStatus::class)],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'estado' => 'estado',
            'nota' => 'nota',
        ];
    }
}
