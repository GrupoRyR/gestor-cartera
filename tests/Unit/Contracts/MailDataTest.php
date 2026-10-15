<?php

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;

it('builds an outgoing mail with sensible defaults', function () {
    $mail = new OutgoingMail(
        company: CompanyCode::SGX,
        to: ['pagos@cliente.com'],
        subject: 'Facturas vencidas',
        html: '<p>Hola</p>',
    );

    expect($mail->cc)->toBe([])
        ->and($mail->replyTo)->toBeNull()
        ->and($mail->attachments)->toBe([])
        ->and($mail->clientId)->toBeNull()
        ->and($mail->category)->toBe('general');
});

it('rejects a mail without recipients', function () {
    expect(fn () => new OutgoingMail(
        company: CompanyCode::SGX,
        to: [],
        subject: 'Facturas vencidas',
        html: '<p>Hola</p>',
    ))->toThrow(InvalidArgumentException::class, 'recipient');
});

it('rejects a blank recipient', function () {
    expect(fn () => new OutgoingMail(
        company: CompanyCode::SDC,
        to: ['pagos@cliente.com', '  '],
        subject: 'Facturas vencidas',
        html: '<p>Hola</p>',
    ))->toThrow(InvalidArgumentException::class, 'recipient');
});
