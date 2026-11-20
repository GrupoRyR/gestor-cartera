<?php

use App\Modules\Soportes\Domain\PaymentMethod;

it('offers the payment methods with their Spanish labels', function () {
    expect(PaymentMethod::options())->toBe([
        'transferencia' => 'Transferencia',
        'consignacion' => 'Consignación',
        'pse' => 'PSE',
        'otro' => 'Otro',
    ]);
});

it('fits every value in the method column', function () {
    foreach (PaymentMethod::cases() as $method) {
        expect(strlen($method->value))->toBeLessThanOrEqual(20);
    }
});
