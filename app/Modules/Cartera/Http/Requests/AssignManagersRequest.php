<?php

namespace App\Modules\Cartera\Http\Requests;

use App\Modules\Cartera\Application\Clients\AssigneeOptions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /cartera/clientes/gestor: the bulk "Asignar gestor" of the client
 * list. Either the chosen clients (clientes[]) or todos_filtrados=1 with the
 * filters of the list (buscar, estado, asesor, vencidos, sin_gestion,
 * gestor), and the new manager (nuevo_gestor: a user id or "sin_asignar").
 * The permission is checked by the route and by Core's
 * ClientAssignmentService.
 */
final class AssignManagersRequest extends FormRequest
{
    /**
     * The names of the client list filters the form carries.
     *
     * @var list<string>
     */
    public const array FILTERS = ['buscar', 'estado', 'asesor', 'vencidos', 'sin_gestion', 'gestor'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'nuevo_gestor' => ['required', 'string', 'regex:'.AssigneeOptions::PATTERN],
            'todos_filtrados' => ['sometimes', 'boolean'],
            'clientes' => ['required_unless:todos_filtrados,1', 'array', 'max:'.(int) config('cartera.assignment.max_bulk')],
            'clientes.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clientes.required_unless' => __('cartera::clientes.asignar.errores.sin_clientes'),
            'nuevo_gestor.required' => __('cartera::clientes.asignar.errores.gestor'),
            'nuevo_gestor.regex' => __('cartera::clientes.asignar.errores.gestor'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nuevo_gestor' => 'gestor',
            'clientes' => 'clientes',
            'clientes.*' => 'cliente',
        ];
    }

    public function allMatching(): bool
    {
        return $this->boolean('todos_filtrados');
    }

    /**
     * @return list<int>
     */
    public function clientIds(): array
    {
        return array_values(array_unique(array_map(intval(...), $this->validated('clientes', []))));
    }

    /**
     * @return array<string, mixed> The list filters posted with "todos los filtrados".
     */
    public function filters(): array
    {
        return $this->only(self::FILTERS);
    }

    public function managerUserId(): ?int
    {
        return AssigneeOptions::managerUserId($this->validated('nuevo_gestor'));
    }
}
