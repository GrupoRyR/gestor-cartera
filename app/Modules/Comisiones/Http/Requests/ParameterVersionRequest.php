<?php

namespace App\Modules\Comisiones\Http\Requests;

use App\Modules\Comisiones\Http\Support\ParameterFields;
use App\Modules\Comisiones\Http\Support\ParameterKind;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The fields every new parameter version carries (§7.2): the day it starts
 * («vigente desde») and the motive, plus the values of its kind, which each
 * subclass validates and turns into the "datos" ParameterMapper describes.
 * The rules a value must meet beyond its shape (tranches that leave no gap,
 * a book without overlaps, the closed periods) are ParameterVersions' and
 * the domain's.
 */
abstract class ParameterVersionRequest extends FormRequest
{
    abstract public function kind(): ParameterKind;

    /**
     * The values of the version as ParameterMapper stores them.
     *
     * @return array<string, mixed>
     */
    abstract public function versionData(): array;

    /**
     * The rules of the kind's own fields.
     *
     * @return array<string, list<mixed>>
     */
    abstract protected function fieldRules(): array;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->fieldRules(),
            'vigente_desde' => ['required', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => __('comisiones::parametros.motivo_requerido'),
            'persona_id.regex' => __('comisiones::parametros.validacion.persona_id'),
            'director_id.regex' => __('comisiones::parametros.validacion.persona_id'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = __('comisiones::parametros.formulario.atributos');

        return is_array($attributes) ? $attributes : [];
    }

    /** The key of the version: the code, team or derived key, or the type for tramos and opciones. */
    public function key(): string
    {
        $key = $this->kind()->keyed() ? $this->validated('clave') : null;

        return $this->kind()->key(is_scalar($key) ? (string) $key : null);
    }

    /** The first day of the version, "Y-m-d". */
    public function from(): string
    {
        return (string) $this->validated('vigente_desde');
    }

    public function reason(): string
    {
        return trim((string) $this->validated('motivo'));
    }

    /** A validated percent field as "0.75". */
    protected function percent(string $field): string
    {
        return ParameterFields::percent($this->validated($field))
            ?? throw new LogicException("The field {$field} passed validation without being a percent.");
    }
}
