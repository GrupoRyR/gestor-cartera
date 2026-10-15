<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Throwaway routes shaped like the module ones: API routes get the "api"
 * middleware and the /api prefix, web routes the "web" middleware.
 */
beforeEach(function () {
    Route::middleware('api')->prefix('api/_test')->group(function () {
        Route::post('validar', function (Request $request) {
            $request->validate(['monto' => ['required', 'numeric']]);

            return ['ok' => true];
        });

        Route::get('privado', fn () => ['ok' => true])->middleware('auth');
    });

    Route::middleware(['web', 'auth'])->get('/_test/privado', fn () => 'ok');
});

it('answers an API validation error with JSON even without an Accept header', function () {
    // The SAP robot and Treasury do not always send Accept: application/json.
    $response = $this->post('/api/_test/validar', ['monto' => 'mucho']);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonStructure(['message', 'errors' => ['monto']]);
});

it('answers an unauthenticated API call with a JSON 401 instead of a redirect', function () {
    $this->get('/api/_test/privado')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/json')
        ->assertJson(['message' => 'Unauthenticated.']);
});

it('keeps sending guests of a web page to the sign-in page', function () {
    $this->get('/_test/privado')->assertRedirect('/login');
});
