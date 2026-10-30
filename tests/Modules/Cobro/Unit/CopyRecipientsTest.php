<?php

use App\Modules\Cobro\Domain\Copies\CopyRecipients;
use App\Modules\Cobro\Domain\Copies\CopySettings;

function cobroCopies(bool $advisor = true): CopySettings
{
    return new CopySettings(
        fixed: ['jefe.cartera@segurex.com'],
        advisor: $advisor,
        managers: [
            ['group' => 'DISTRIBUCION', 'emails' => ['gerente.distribucion@segurex.com']],
            ['group' => 'CONS. RESIDENCIAL', 'emails' => ['gerente.residencial@segurex.com']],
        ],
    );
}

it('copies the fixed addresses, the advisor and the managers of the client group, in that order', function () {
    expect(CopyRecipients::resolve(cobroCopies(), 'asesor41@segurex.com', 'DISTRIBUCION'))
        ->toBe(['jefe.cartera@segurex.com', 'asesor41@segurex.com', 'gerente.distribucion@segurex.com']);
});

it('matches the group without regard to case, accents or spacing', function (string $group) {
    expect(CopyRecipients::resolve(cobroCopies(), null, $group))
        ->toBe(['jefe.cartera@segurex.com', 'gerente.residencial@segurex.com']);
})->with([
    'as SAP writes it' => ['CONS. RESIDENCIAL'],
    'lowercase' => ['cons. residencial'],
    'extra spaces' => ['  Cons.   Residencial '],
    'accents' => ['Cons. Residéncial'],
]);

it('copies no manager without a group or with another group', function (?string $group) {
    expect(CopyRecipients::resolve(cobroCopies(), null, $group))->toBe(['jefe.cartera@segurex.com']);
})->with([
    'no group' => [null],
    'blank group' => ['  '],
    'other group' => ['INDUSTRIA'],
]);

it('leaves the advisor out when disabled, missing or blank', function (bool $enabled, ?string $email) {
    expect(CopyRecipients::resolve(cobroCopies($enabled), $email, null))->toBe(['jefe.cartera@segurex.com']);
})->with([
    'disabled' => [false, 'asesor41@segurex.com'],
    'without e-mail' => [true, null],
    'blank e-mail' => [true, '   '],
]);

it('drops duplicates and the To addresses, keeping the first spelling', function () {
    $settings = new CopySettings(
        fixed: [' Jefe.Cartera@segurex.com ', '', 'tesoreria@cliente.example'],
        managers: [['group' => 'DISTRIBUCION', 'emails' => ['jefe.cartera@SEGUREX.com', 'gerente.distribucion@segurex.com']]],
    );

    expect(CopyRecipients::resolve($settings, 'GERENTE.DISTRIBUCION@segurex.com', 'Distribución', to: ['Tesoreria@Cliente.example']))
        ->toBe(['Jefe.Cartera@segurex.com', 'GERENTE.DISTRIBUCION@segurex.com']);
});

it('splits a list written one per line or with commas', function () {
    expect(CopyRecipients::split("a@x.example, b@x.example\r\n\r\nc@x.example;A@X.example  "))
        ->toBe(['a@x.example', 'b@x.example', 'c@x.example']);
});

it('reads settings from their stored shape, leaving out incomplete managers', function () {
    $settings = CopySettings::fromArray([
        'fixed' => ['a@x.example', 'A@x.example'],
        'advisor' => false,
        'managers' => [['group' => ' DISTRIBUCION ', 'emails' => ['b@x.example']], ['group' => '', 'emails' => ['c@x.example']], ['group' => 'X', 'emails' => []]],
    ], isDefault: true);

    expect($settings->fixed)->toBe(['a@x.example'])
        ->and($settings->advisor)->toBeFalse()
        ->and($settings->managers)->toBe([['group' => 'DISTRIBUCION', 'emails' => ['b@x.example']]])
        ->and($settings->isDefault)->toBeTrue();
});

it('keeps only the addresses of the allowed domains, in order', function () {
    expect(CopyRecipients::allowed(
        ['Ana@Segurex.com', 'tesoreria@cliente.example', ' asesora.sdc@DERIVADOSCOL.COM ', 'x@mail.segurex.com', 'sin-arroba', 'raro@cliente.example@segurex.com.co'],
        ['segurex.com', 'Derivadoscol.com'],
    ))->toBe(['Ana@Segurex.com', ' asesora.sdc@DERIVADOSCOL.COM ']);
});

it('compares the part after the last @', function () {
    expect(CopyRecipients::allowed(['"a@b"@segurex.com', 'ana@segurex.com@cliente.example'], ['segurex.com']))
        ->toBe(['"a@b"@segurex.com']);
});

it('allows nothing without domains', function () {
    expect(CopyRecipients::allowed(['ana@segurex.com'], []))->toBe([]);
});
