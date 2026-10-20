<?php

use App\Modules\Cobro\Domain\Copies\CopyRecipients;
use App\Modules\Cobro\Domain\Copies\CopySettings;

function cobroCopies(bool $advisor = true): CopySettings
{
    return new CopySettings(
        fixed: ['miguel.martinez@segurex.com'],
        advisor: $advisor,
        managers: [
            ['group' => 'DISTRIBUCION', 'emails' => ['jessika.quintero@segurex.com']],
            ['group' => 'CONS. RESIDENCIAL', 'emails' => ['andrea.hincapie@segurex.com']],
        ],
    );
}

it('copies the fixed addresses, the advisor and the managers of the client group, in that order', function () {
    expect(CopyRecipients::resolve(cobroCopies(), 'asesor41@segurex.com', 'DISTRIBUCION'))
        ->toBe(['miguel.martinez@segurex.com', 'asesor41@segurex.com', 'jessika.quintero@segurex.com']);
});

it('matches the group without regard to case, accents or spacing', function (string $group) {
    expect(CopyRecipients::resolve(cobroCopies(), null, $group))
        ->toBe(['miguel.martinez@segurex.com', 'andrea.hincapie@segurex.com']);
})->with([
    'as SAP writes it' => ['CONS. RESIDENCIAL'],
    'lowercase' => ['cons. residencial'],
    'extra spaces' => ['  Cons.   Residencial '],
    'accents' => ['Cons. Residéncial'],
]);

it('copies no manager without a group or with another group', function (?string $group) {
    expect(CopyRecipients::resolve(cobroCopies(), null, $group))->toBe(['miguel.martinez@segurex.com']);
})->with([
    'no group' => [null],
    'blank group' => ['  '],
    'other group' => ['INDUSTRIA'],
]);

it('leaves the advisor out when disabled, missing or blank', function (bool $enabled, ?string $email) {
    expect(CopyRecipients::resolve(cobroCopies($enabled), $email, null))->toBe(['miguel.martinez@segurex.com']);
})->with([
    'disabled' => [false, 'asesor41@segurex.com'],
    'without e-mail' => [true, null],
    'blank e-mail' => [true, '   '],
]);

it('drops duplicates and the To addresses, keeping the first spelling', function () {
    $settings = new CopySettings(
        fixed: [' Miguel.Martinez@segurex.com ', '', 'tesoreria@cliente.example'],
        managers: [['group' => 'DISTRIBUCION', 'emails' => ['miguel.martinez@SEGUREX.com', 'jessika.quintero@segurex.com']]],
    );

    expect(CopyRecipients::resolve($settings, 'JESSIKA.QUINTERO@segurex.com', 'Distribución', to: ['Tesoreria@Cliente.example']))
        ->toBe(['Miguel.Martinez@segurex.com', 'JESSIKA.QUINTERO@segurex.com']);
});

it('reads settings from their stored shape, leaving out incomplete managers', function () {
    $settings = CopySettings::fromArray([
        'fixed' => ['a@x.example', 'A@x.example'],
        'advisor' => false,
        'managers' => [['group' => ' DISTRIBUCION ', 'emails' => ['b@x.example']], ['group' => '', 'emails' => ['c@x.example']], ['group' => 'X', 'emails' => []]],
    ]);

    expect($settings->fixed)->toBe(['a@x.example'])
        ->and($settings->advisor)->toBeFalse()
        ->and($settings->managers)->toBe([['group' => 'DISTRIBUCION', 'emails' => ['b@x.example']]]);
});
