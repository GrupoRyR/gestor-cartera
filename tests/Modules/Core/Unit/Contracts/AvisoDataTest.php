<?php

use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use Carbon\CarbonImmutable;

it('builds a notice with sensible defaults', function () {
    $aviso = new Aviso(recipients: [7], kind: 'cartera.compromiso', title: 'Pago parcial recibido');

    expect($aviso->recipients)->toBe([7])
        ->and($aviso->body)->toBeNull()
        ->and($aviso->company)->toBeNull()
        ->and($aviso->subjectType)->toBeNull()
        ->and($aviso->subjectId)->toBeNull()
        ->and($aviso->key)->toBeNull()
        ->and($aviso->data)->toBe([]);
});

it('keeps each recipient once, in the order given', function () {
    $aviso = new Aviso(recipients: [5, 3, 5, 9, 3], kind: 'cartera.compromiso', title: 'Pago parcial recibido');

    expect($aviso->recipients)->toBe([5, 3, 9]);
});

it('rejects invalid recipients', function (array $recipients) {
    expect(fn () => new Aviso(recipients: $recipients, kind: 'cartera.compromiso', title: 'Pago parcial recibido'))
        ->toThrow(InvalidArgumentException::class, 'recipient');
})->with([
    'none' => [[]],
    'zero' => [[3, 0]],
    'negative' => [[-1]],
    'a string' => [['4']],
]);

it('rejects an invalid field', function (array $fields, string $message) {
    expect(fn () => new Aviso(...[
        'recipients' => [1],
        'kind' => 'cartera.compromiso',
        'title' => 'Pago parcial recibido',
        ...$fields,
    ]))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'blank kind' => [['kind' => '  '], 'kind'],
    'long kind' => [['kind' => str_repeat('k', 41)], 'kind'],
    'blank title' => [['title' => ''], 'title'],
    'long title' => [['title' => str_repeat('t', 161)], 'title'],
    'short company' => [['company' => 'SG'], 'company'],
    'lowercase company' => [['company' => 'sgx'], 'company'],
    'long subject type' => [['subjectType' => str_repeat('s', 21)], 'subject type'],
    'blank key' => [['key' => ' '], 'key'],
    'long key' => [['key' => str_repeat('k', 81)], 'key'],
]);

it('accepts every field at its limit', function () {
    $aviso = new Aviso(
        recipients: [1],
        kind: str_repeat('k', 40),
        title: str_repeat('á', 160),
        body: 'Ferretería El Tornillo abonó $ 1.000.000.',
        company: 'SDC',
        subjectType: str_repeat('s', 20),
        subjectId: 12,
        key: str_repeat('k', 80),
        data: ['amount' => 1_000_000],
    );

    expect($aviso->company)->toBe('SDC')
        ->and($aviso->subjectId)->toBe(12)
        ->and($aviso->data)->toBe(['amount' => 1_000_000]);
});

it('tells whether a stored notice is read and resolved', function () {
    $notice = fn (?CarbonImmutable $readAt, ?CarbonImmutable $resolvedAt): Notice => new Notice(
        id: 1,
        userId: 2,
        company: 'SGX',
        kind: 'cartera.compromiso',
        title: 'Pago parcial recibido',
        body: null,
        subjectType: 'commitment',
        subjectId: 3,
        key: 'commitment:3:partial',
        data: [],
        readAt: $readAt,
        resolvedAt: $resolvedAt,
        createdAt: CarbonImmutable::parse('2026-11-20 08:00:00'),
    );
    $now = CarbonImmutable::parse('2026-11-21 10:00:00');

    expect($notice(null, null)->isRead())->toBeFalse()
        ->and($notice(null, null)->isResolved())->toBeFalse()
        ->and($notice($now, null)->isRead())->toBeTrue()
        ->and($notice($now, null)->isResolved())->toBeFalse()
        ->and($notice(null, $now)->isRead())->toBeFalse()
        ->and($notice(null, $now)->isResolved())->toBeTrue();
});
