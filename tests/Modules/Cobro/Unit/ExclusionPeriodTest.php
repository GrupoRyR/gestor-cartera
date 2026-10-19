<?php

use App\Modules\Cobro\Domain\Exclusions\ExclusionPeriod;
use Carbon\CarbonImmutable;

it('applies until the end of the expiry day, or forever without one', function (?string $expiresOn, bool $active) {
    $today = CarbonImmutable::parse('2026-10-21 23:59:00', 'America/Bogota');

    expect(ExclusionPeriod::isActive($expiresOn === null ? null : CarbonImmutable::parse($expiresOn), $today))->toBe($active);
})->with([
    'no expiry' => [null, true],
    'expires later' => ['2026-11-30', true],
    'expires today' => ['2026-10-21', true],
    'expired yesterday' => ['2026-10-20', false],
]);
