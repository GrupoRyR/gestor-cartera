<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;

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
