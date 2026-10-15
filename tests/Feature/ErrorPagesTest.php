<?php

use Illuminate\Support\Facades\Route;
use Tests\Feature\Design\Support\Html;

/**
 * Asserts the Spanish error page: its heading, the guest layout and the way home.
 */
function assertErrorPage(string $content, string $heading): void
{
    $html = Html::parse($content);

    expect($html->one('//html')->getAttribute('lang'))->toBe('es')
        ->and($html->text('//h1'))->toBe($heading)
        ->and($html->count('//nav'))->toBe(0)
        ->and($html->one('//main//a[normalize-space(.)="Volver al inicio"]')->getAttribute('href'))->toBe(url('/'));
}

it('renders the Spanish 404 page for a missing URL', function () {
    $response = $this->get('/esta-pagina-no-existe')->assertNotFound();

    assertErrorPage($response->getContent() ?: '', 'No encontramos esta página');
});

it('renders a Spanish page for every error status', function (int $status, string $heading) {
    Route::get('/prueba-de-error', fn () => abort($status));

    $response = $this->get('/prueba-de-error')->assertStatus($status);

    assertErrorPage($response->getContent() ?: '', $heading);
})->with([
    [403, 'No tienes permiso para ver esta página'],
    [404, 'No encontramos esta página'],
    [419, 'Tu sesión expiró'],
    [429, 'Demasiadas solicitudes'],
    [500, 'Algo salió mal'],
    [503, 'Estamos actualizando la aplicación'],
]);

it('renders the Spanish 500 page for an unexpected exception without debug mode', function () {
    config(['app.debug' => false]);
    Route::get('/prueba-de-fallo', fn () => throw new RuntimeException('Fallo de prueba'));

    $response = $this->get('/prueba-de-fallo')->assertStatus(500);

    assertErrorPage($response->getContent() ?: '', 'Algo salió mal');
    expect($response->getContent())->not->toContain('Fallo de prueba');
});
