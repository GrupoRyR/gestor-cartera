<?php

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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

it('stores the e-mail trimmed and in lowercase so one person cannot appear twice', function () {
    $user = User::factory()->create(['email' => '  Ana.Gomez@Segurex.COM ']);

    expect($user->email)->toBe('ana.gomez@segurex.com')
        ->and(User::normalizeEmail(' Ana.Gomez@Segurex.COM '))->toBe('ana.gomez@segurex.com')
        ->and(User::where('email', 'ana.gomez@segurex.com')->value('id'))->toBe($user->id)
        ->and(fn () => User::factory()->create(['email' => 'ANA.GOMEZ@segurex.com']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('restores a soft-deleted user added again with restoreOrCreate, keeping the same row', function () {
    $user = User::factory()->create(['email' => 'ana.gomez@segurex.com', 'name' => 'Ana Gómez']);
    $user->delete();

    // The documented creation path: look the person up by the normalized e-mail.
    $again = User::withTrashed()->restoreOrCreate(['email' => User::normalizeEmail('Ana.Gomez@Segurex.com ')], ['name' => 'Ana María Gómez']);

    expect($again->id)->toBe($user->id)
        ->and($again->trashed())->toBeFalse()
        ->and(User::find($user->id)?->deleted_at)->toBeNull()
        ->and(User::withTrashed()->count())->toBe(1);
});

it('rejects a plain create() for the e-mail of a soft-deleted user', function () {
    // Documents the constraint: every creation path uses restoreOrCreate().
    $user = User::factory()->create(['email' => 'ana.gomez@segurex.com']);
    $user->delete();

    expect(fn () => User::create(['name' => 'Ana Gómez', 'email' => 'ana.gomez@segurex.com']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('assigns roles through spatie/laravel-permission', function () {
    Role::findOrCreate('analista_cartera', 'web');
    $user = User::factory()->create();

    $user->assignRole('analista_cartera');

    expect($user->hasRole('analista_cartera'))->toBeTrue();
});
