<?php

use App\Modules\Cartera\Application\Calls\NewCall;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;

function newCallWith(string $phone = '3001234567', ?string $contactName = null, array $invoiceIds = []): NewCall
{
    return new NewCall(
        clientId: 7,
        channel: CallChannel::Call,
        phone: $phone,
        contactName: $contactName,
        outcome: CallOutcome::Answered,
        notes: null,
        agreement: CallAgreement::None,
        invoiceIds: $invoiceIds,
    );
}

it('keeps what the form sent', function () {
    $call = newCallWith(contactName: 'Juan Pérez', invoiceIds: [3, 4]);

    expect($call->clientId)->toBe(7)
        ->and($call->phone)->toBe('3001234567')
        ->and($call->contactName)->toBe('Juan Pérez')
        ->and($call->followUpOn)->toBeNull()
        ->and($call->occurredAt)->toBeNull()
        ->and($call->invoiceIds)->toBe([3, 4]);
});

it('needs a phone of up to 40 characters', function (string $phone) {
    newCallWith(phone: $phone);
})->with([
    'blank' => ['   '],
    'too long' => [str_repeat('3', 41)],
])->throws(InvalidArgumentException::class);

it('takes a contact name of up to 150 characters', function () {
    newCallWith(contactName: str_repeat('a', 151));
})->throws(InvalidArgumentException::class);

it('takes positive invoice ids only', function () {
    newCallWith(invoiceIds: [3, 0]);
})->throws(InvalidArgumentException::class);
