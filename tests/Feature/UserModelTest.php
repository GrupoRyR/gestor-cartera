<?php

use App\Models\User;
use Spatie\Permission\Models\Role;

it('creates users without a password because sign-in is with Microsoft', function () {
    $user = User::factory()->create();

    expect($user->password)->toBeNull()
        ->and($user->is_active)->toBeTrue()
        ->and($user->microsoft_id)->not->toBeNull();
});

it('soft deletes users so the audit trail keeps who they were', function () {
    $user = User::factory()->create();

    $user->delete();

    expect(User::find($user->id))->toBeNull()
        ->and(User::withTrashed()->find($user->id))->not->toBeNull();
});

it('assigns roles through spatie/laravel-permission', function () {
    Role::findOrCreate('analista_cartera', 'web');
    $user = User::factory()->create();

    $user->assignRole('analista_cartera');

    expect($user->hasRole('analista_cartera'))->toBeTrue();
});
