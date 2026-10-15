<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Domain\Status\StatusDecision;

it('keeps the current status', function () {
    $decision = StatusDecision::keep();

    expect($decision->isKeep())->toBeTrue()
        ->and($decision->isSet())->toBeFalse()
        ->and($decision->isRecompute())->toBeFalse()
        ->and($decision->status())->toBeNull();
});

it('sets a given status', function () {
    $decision = StatusDecision::set(ClientStatus::Defaulted);

    expect($decision->isSet())->toBeTrue()
        ->and($decision->isKeep())->toBeFalse()
        ->and($decision->isRecompute())->toBeFalse()
        ->and($decision->status())->toBe(ClientStatus::Defaulted);
});

it('asks the caller to recompute the automatic status', function () {
    $decision = StatusDecision::recompute();

    expect($decision->isRecompute())->toBeTrue()
        ->and($decision->isKeep())->toBeFalse()
        ->and($decision->isSet())->toBeFalse()
        ->and($decision->status())->toBeNull();
});

it('compares decisions by value', function () {
    expect(StatusDecision::set(ClientStatus::Committed))->toEqual(StatusDecision::set(ClientStatus::Committed))
        ->and(StatusDecision::set(ClientStatus::Committed))->not->toEqual(StatusDecision::set(ClientStatus::Defaulted))
        ->and(StatusDecision::keep())->not->toEqual(StatusDecision::recompute());
});
