<?php

namespace App\Modules\Cartera\Http\Requests;

use App\Modules\Cartera\Application\Clients\AssigneeOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cartera/clientes/{client}/gestor: the new manager of one client,
 * chosen in the client file (nuevo_gestor: a user id or "sin_asignar"). The
 * permission is checked by the route and by Core's ClientAssignmentService.
 */
final class AssignManagerRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'nuevo_gestor' => ['required', 'string', 'regex:'.AssigneeOptions::PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nuevo_gestor.required' => __('cartera::ficha.gestor.elige'),
            'nuevo_gestor.regex' => __('cartera::ficha.gestor.elige'),
        ];
    }

    public function managerUserId(): ?int
    {
        return AssigneeOptions::managerUserId($this->validated('nuevo_gestor'));
    }
}
