<?php

use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\FakeAuditLogger;

it('records entries in order', function () {
    $audit = new FakeAuditLogger;

    $audit->record('cobro.exclusion.created', 'cobro_exclusion', 7, ['reason' => 'Acuerdo de pago'], ['client_id' => 10]);
    $audit->record('core.user.deactivated', 'user', 3);

    expect($audit->entries())->toEqual([
        new AuditEntry('cobro.exclusion.created', 'cobro_exclusion', 7, ['reason' => 'Acuerdo de pago'], ['client_id' => 10]),
        new AuditEntry('core.user.deactivated', 'user', 3, [], []),
    ]);
});

it('rejects actions that do not follow the naming pattern', function (string $action) {
    expect(fn () => (new FakeAuditLogger)->record($action))
        ->toThrow(InvalidArgumentException::class, $action);
})->with(['cobro.created', 'Cobro.Exclusion.Created', 'cobro exclusion created', 'cobro..created', '']);

it('asserts recorded actions', function () {
    $audit = new FakeAuditLogger;

    $audit->assertNothingRecorded();
    expect(fn () => $audit->assertRecorded('cobro.exclusion.created'))->toThrow(AssertionFailedError::class);

    $audit->record('cobro.exclusion.created', 'cobro_exclusion', 7);

    $audit->assertRecorded('cobro.exclusion.created');
    $audit->assertRecorded('cobro.exclusion.created', fn (AuditEntry $entry): bool => $entry->subjectId === 7);
    expect(fn () => $audit->assertRecorded('cobro.exclusion.created', fn (AuditEntry $entry): bool => $entry->subjectId === 8))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => $audit->assertRecorded('cobro.exclusion.deleted'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $audit->assertNothingRecorded())->toThrow(AssertionFailedError::class);
});
