<?php

use App\Modules\Core\Contracts\Data\MailAttachment;
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

it('attaches a file as a regular attachment by default', function () {
    expect((new MailAttachment('estado.pdf', 'application/pdf', '%PDF'))->contentId)->toBeNull();
});

it('attaches an image inline under its content id', function () {
    $logo = new MailAttachment('logo.png', 'image/png', 'png-bytes', contentId: 'sgx-logo');

    expect($logo->contentId)->toBe('sgx-logo')
        ->and($logo->isInline())->toBeTrue()
        ->and((new MailAttachment('estado.pdf', 'application/pdf', '%PDF'))->isInline())->toBeFalse();
});

it('rejects a content id an HTML cid: reference cannot carry', function (string $contentId) {
    expect(fn () => new MailAttachment('logo.png', 'image/png', 'png-bytes', contentId: $contentId))
        ->toThrow(InvalidArgumentException::class, 'content id');
})->with([
    'blank' => [''],
    'spaces' => ['sgx logo'],
    'angle brackets' => ['<sgx-logo>'],
    'a quote' => ['sgx"logo'],
]);
