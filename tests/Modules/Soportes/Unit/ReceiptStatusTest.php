<?php

use App\Modules\Soportes\Domain\Exceptions\InvalidReceiptTransition;
use App\Modules\Soportes\Domain\ReceiptStatus;

it('lets a pending receipt be applied or rejected', function (ReceiptStatus $to) {
    expect(ReceiptStatus::Pending->canMoveTo($to))->toBeTrue()
        ->and(ReceiptStatus::Pending->moveTo($to))->toBe($to);
})->with([
    'aplicado' => [ReceiptStatus::Applied],
    'rechazado' => [ReceiptStatus::Rejected],
]);

it('refuses every other transition', function (ReceiptStatus $from, ReceiptStatus $to) {
    expect($from->canMoveTo($to))->toBeFalse()
        ->and(fn () => $from->moveTo($to))->toThrow(InvalidReceiptTransition::class);
})->with([
    'pendiente a pendiente' => [ReceiptStatus::Pending, ReceiptStatus::Pending],
    'aplicado a rechazado' => [ReceiptStatus::Applied, ReceiptStatus::Rejected],
    'aplicado a pendiente' => [ReceiptStatus::Applied, ReceiptStatus::Pending],
    'aplicado a aplicado' => [ReceiptStatus::Applied, ReceiptStatus::Applied],
    'rechazado a aplicado' => [ReceiptStatus::Rejected, ReceiptStatus::Applied],
    'rechazado a pendiente' => [ReceiptStatus::Rejected, ReceiptStatus::Pending],
]);

it('names both statuses in the refusal', function () {
    expect(fn () => ReceiptStatus::Applied->moveTo(ReceiptStatus::Rejected))
        ->toThrow(InvalidReceiptTransition::class, 'aplicado -> rechazado');
});

it('treats applied and rejected as final', function () {
    expect(ReceiptStatus::Pending->isFinal())->toBeFalse()
        ->and(ReceiptStatus::Applied->isFinal())->toBeTrue()
        ->and(ReceiptStatus::Rejected->isFinal())->toBeTrue();
});

it('stores the Spanish values', function () {
    expect(array_map(fn (ReceiptStatus $status): string => $status->value, ReceiptStatus::cases()))
        ->toBe(['pendiente', 'aplicado', 'rechazado']);
});

it('has a sentence-case label and a chip tone for each status', function () {
    expect(ReceiptStatus::options())->toBe([
        'pendiente' => 'Pendiente',
        'aplicado' => 'Aplicado',
        'rechazado' => 'Rechazado',
    ])
        ->and(ReceiptStatus::Pending->tone())->toBe('warning')
        ->and(ReceiptStatus::Applied->tone())->toBe('success')
        ->and(ReceiptStatus::Rejected->tone())->toBe('danger');
});
