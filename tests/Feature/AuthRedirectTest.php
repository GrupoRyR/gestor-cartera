<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', 'auth'])->get('/_test/privada', fn () => 'privada');
    Route::middleware(['web', 'guest'])->get('/_test/solo-invitados', fn () => 'invitado');
});

it('sends guests to the Microsoft sign-in page served by Core', function () {
    $this->get('/_test/privada')->assertRedirect('/login');
});

it('sends signed-in users away from guest-only pages to their home', function () {
    $this->actingAs(User::factory()->create())
        ->get('/_test/solo-invitados')
        ->assertRedirect('/');
});
