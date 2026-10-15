<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Dot keys of a translation file, without the per-application validation
 * sections ("custom" and "attributes").
 *
 * @param  array<string, mixed>  $lines
 * @return list<string>
 */
function translationKeys(array $lines): array
{
    return array_keys(Arr::dot(Arr::except($lines, ['custom', 'attributes'])));
}

it('shows validation errors in Spanish', function () {
    $errors = Validator::make(
        ['valor' => 'mil', 'email' => 'pagos'],
        ['nombre' => 'required', 'valor' => 'numeric', 'email' => 'email'],
    )->errors();

    expect($errors->first('nombre'))->toBe('El campo nombre es obligatorio.')
        ->and($errors->first('valor'))->toBe('El campo valor debe ser un número.')
        ->and($errors->first('email'))->toBe('El campo correo electrónico debe ser una dirección de correo válida.');
});

it('shows size errors in Spanish for each kind of value', function () {
    $errors = Validator::make(
        ['nota' => 'Hola', 'cuota' => 3],
        ['nota' => 'string|min:10', 'cuota' => 'numeric|max:2'],
    )->errors();

    expect($errors->first('nota'))->toBe('El campo nota debe tener al menos 10 caracteres.')
        ->and($errors->first('cuota'))->toBe('El campo cuota no debe ser mayor que 2.');
});

it('translates every line of the framework files', function (string $file) {
    $english = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$file}.php");
    $spanish = require lang_path("es/{$file}.php");

    expect(array_values(array_diff(translationKeys($english), translationKeys($spanish))))->toBe([])
        ->and(array_values(array_diff(translationKeys($spanish), translationKeys($english))))->toBe([]);
})->with(['auth', 'pagination', 'passwords', 'validation']);

it('keeps the custom messages and attribute names of validation', function () {
    $spanish = require lang_path('es/validation.php');

    expect($spanish['custom'])->toBeArray()
        ->and($spanish['attributes'])->toBeArray()
        ->and(__('validation.attributes.password'))->toBe('contraseña');
});

it('does not leave an English copy of the framework files', function () {
    expect(is_dir(lang_path('en')))->toBeFalse();
});
