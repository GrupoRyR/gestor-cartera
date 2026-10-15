<?php

it('answers the health check used after each deploy', function () {
    $this->get('/up')->assertOk();
});

it('runs on Colombian time and in Spanish', function () {
    expect(config('app.timezone'))->toBe('America/Bogota')
        ->and(app()->getLocale())->toBe('es');
});
