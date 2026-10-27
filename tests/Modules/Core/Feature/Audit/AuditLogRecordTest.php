<?php

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    app(AuditLogger::class)->record('core.user.deactivated', 'user', 7, ['is_active' => ['old' => true, 'new' => false]]);

    $this->entry = AuditLogRecord::query()->sole();
});

it('refuses to update an entry and leaves the row unchanged', function (string $method) {
    $this->entry->action = 'core.user.activated';
    $this->entry->subject_id = '8';

    expect(fn () => $this->entry->{$method}())->toThrow(LogicException::class, 'immutable');

    $stored = AuditLogRecord::query()->sole();

    expect($stored->action)->toBe('core.user.deactivated')
        ->and($stored->subject_id)->toBe('7')
        ->and($stored->changes)->toEqual(['is_active' => ['old' => true, 'new' => false]]);
})->with(['save', 'update', 'saveQuietly']);

it('refuses to delete an entry and keeps the row', function (string $method) {
    expect(fn () => $this->entry->{$method}())->toThrow(LogicException::class, 'immutable');

    expect(AuditLogRecord::query()->whereKey($this->entry->id)->exists())->toBeTrue();
})->with(['delete', 'deleteQuietly', 'forceDelete']);

it('refuses to remove for good a user who has entries, and the entry still names them', function () {
    $this->actingAs($user = User::factory()->create());
    app(AuditLogger::class)->record('core.user.updated', 'user', $user->id);

    expect(fn () => $user->forceDelete())->toThrow(QueryException::class);

    expect(User::withTrashed()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AuditLogRecord::query()->where('action', 'core.user.updated')->sole()->user_id)->toBe($user->id);
});

it('still removes for good a user without entries', function () {
    $user = User::factory()->create();

    $user->forceDelete();

    expect(User::withTrashed()->whereKey($user->id)->exists())->toBeFalse();
});

it('keeps the user link restricted and indexed', function () {
    $foreignKey = collect(Schema::getForeignKeys('audit_logs'))->sole(fn (array $key) => $key['columns'] === ['user_id']);

    expect($foreignKey['foreign_table'])->toBe('users')
        ->and(strtolower($foreignKey['on_delete']))->toBe('restrict')
        ->and(Schema::hasIndex('audit_logs', ['user_id']))->toBeTrue();
});
