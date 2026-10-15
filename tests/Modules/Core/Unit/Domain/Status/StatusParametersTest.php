<?php

use App\Modules\Core\Domain\Status\StatusParameters;

it('keeps the grace days and the due soon window', function () {
    $params = new StatusParameters(graceDays: 15, dueSoonDays: 5);

    expect($params->graceDays)->toBe(15)
        ->and($params->dueSoonDays)->toBe(5);
});

it('accepts zero days for both parameters', function () {
    $params = new StatusParameters(graceDays: 0, dueSoonDays: 0);

    expect($params->graceDays)->toBe(0)
        ->and($params->dueSoonDays)->toBe(0);
});

it('rejects negative grace days', function () {
    new StatusParameters(graceDays: -1, dueSoonDays: 5);
})->throws(InvalidArgumentException::class, 'Grace days');

it('rejects a negative due soon window', function () {
    new StatusParameters(graceDays: 15, dueSoonDays: -1);
})->throws(InvalidArgumentException::class, 'Due soon days');
