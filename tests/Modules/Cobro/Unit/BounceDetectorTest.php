<?php

use App\Modules\Cobro\Domain\Mail\BounceDetector;
use App\Modules\Cobro\Domain\Mail\InboundEnvelope;
use App\Modules\Cobro\Domain\Mail\MessageIds;

// The mailbox domains and the tenant's (cobro.tenant_domains).
const COBRO_OWN_DOMAINS = ['segurex.com', 'derivadoscol.com', 'gruporyr.onmicrosoft.com'];

const COBRO_EXCHANGE_SENDER = 'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com';

it('tells delivery reports from replies by their message class, sender and authentication', function (string $from, ?string $class, ?string $authAs, string $kind) {
    expect((new BounceDetector)->classify(new InboundEnvelope($from, $class, $authAs, COBRO_OWN_DOMAINS)))->toBe($kind);
})->with([
    'our Exchange report' => [COBRO_EXCHANGE_SENDER, 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::OWN_REPORT],
    'our postmaster, in any case' => ['Postmaster@Derivadoscol.com', 'report.ipm.note.ndr', 'internal', BounceDetector::OWN_REPORT],
    'our tenant postmaster' => ['postmaster@gruporyr.onmicrosoft.com', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::OWN_REPORT],
    'our tenant Exchange sender' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@gruporyr.onmicrosoft.com', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::OWN_REPORT],
    'our tenant postmaster from outside' => ['postmaster@gruporyr.onmicrosoft.com', 'REPORT.IPM.Note.NDR', 'Anonymous', BounceDetector::EXTERNAL_REPORT],
    'an Exchange sender of another tenant' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@attacker.onmicrosoft.com', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::REPLY],
    'our Exchange sender from outside' => [COBRO_EXCHANGE_SENDER, 'REPORT.IPM.Note.NDR', 'Anonymous', BounceDetector::REPLY],
    'our Exchange sender, authentication unknown' => [COBRO_EXCHANGE_SENDER, 'REPORT.IPM.Note.NDR', null, BounceDetector::REPLY],
    'an Exchange sender of another domain' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@attacker.example', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::REPLY],
    'a subdomain of ours' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@mail.segurex.com', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::REPLY],
    'an Exchange sender without its 32-digit id' => ['MicrosoftExchange@segurex.com', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::REPLY],
    'an employee of ours' => ['miguel.martinez@segurex.com', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::REPLY],
    'a client reply titled as a report' => ['tesoreria@cliente.example', 'IPM.Note', 'Anonymous', BounceDetector::REPLY],
    'our Exchange, an ordinary mail' => [COBRO_EXCHANGE_SENDER, 'IPM.Note', 'Internal', BounceDetector::REPLY],
    'a delay report' => [COBRO_EXCHANGE_SENDER, 'REPORT.IPM.Note.Delayed.DR', 'Internal', BounceDetector::REPLY],
    'a delivery receipt' => [COBRO_EXCHANGE_SENDER, 'REPORT.IPM.Note.DR', 'Internal', BounceDetector::REPLY],
    'a read receipt' => [COBRO_EXCHANGE_SENDER, 'REPORT.IPM.Note.IPNRN', 'Internal', BounceDetector::REPLY],
    'Gmail report' => ['mailer-daemon@googlemail.com', 'REPORT.IPM.Note.NDR', 'Anonymous', BounceDetector::EXTERNAL_REPORT],
    'a postmaster elsewhere' => ['postmaster@cliente.example', 'REPORT.IPM.Note.NDR', null, BounceDetector::EXTERNAL_REPORT],
    'mail delivery subsystem' => ['Mail.Delivery.Subsystem@cliente.example', 'REPORT.IPM.Note.NDR', 'Anonymous', BounceDetector::EXTERNAL_REPORT],
    'a client mailbox with a report class' => ['tesoreria@cliente.example', 'REPORT.IPM.Note.NDR', 'Anonymous', BounceDetector::REPLY],
    'Gmail delay' => ['mailer-daemon@googlemail.com', 'REPORT.IPM.Note.Delayed.DR', 'Anonymous', BounceDetector::REPLY],
    'Gmail plain-text report' => ['mailer-daemon@googlemail.com', 'IPM.Note', 'Anonymous', BounceDetector::REPLY],
    'no message class' => [COBRO_EXCHANGE_SENDER, null, 'Internal', BounceDetector::REPLY],
    'no sender' => ['', 'REPORT.IPM.Note.NDR', 'Internal', BounceDetector::REPLY],
]);

it('knows the message class of a delivery failure report', function (?string $class, bool $failure) {
    expect(BounceDetector::isFailureClass($class))->toBe($failure);
})->with([
    ['REPORT.IPM.Note.NDR', true],
    ['Report.IPM.Note.SMIME.NDR', true],
    ['REPORT.IPM.Note.Delayed.DR', false],
    ['REPORT.IPM.Note.DR', false],
    ['REPORT.NDR', false],
    ['IPM.Note', false],
    ['IPM.Note.NDR', false],
    [null, false],
]);

it('reads the SMTP status line as the reason, skipping header lines', function () {
    $body = "Your message couldn't be delivered.\nDate: Tue, 21 Oct 2026 4.5.6 header\nRemote server returned '550 5.1.1 User unknown'\n";

    expect((new BounceDetector)->read($body)->reason)->toBe("Remote server returned '550 5.1.1 User unknown'");
});

it('falls back to the explaining sentence and then to the preview', function () {
    $detector = new BounceDetector;

    expect($detector->read("Hola\nEl buzón no existe en este dominio.\n")->reason)->toBe('El buzón no existe en este dominio.')
        ->and($detector->read("Hola\nNada útil\n", 'Vista previa del informe')->reason)->toBe('Vista previa del informe')
        ->and($detector->read('')->reason)->toBe('Sin motivo en el informe de rebote.');
});

it('clips long reasons to 300 characters', function () {
    $reason = (new BounceDetector)->read('550 5.1.1 '.str_repeat('x', 400))->reason;

    expect(mb_strlen($reason))->toBe(300)->and($reason)->toEndWith('…');
});

it('names the failed address among the original recipients', function () {
    $body = 'Your message to pagos@cliente.example was rejected by mx.cliente.example; reported by postmaster@cliente.example';

    expect((new BounceDetector)->read($body, recipients: ['Pagos@Cliente.example', 'otro@cliente.example'])->recipient)->toBe('pagos@cliente.example')
        ->and((new BounceDetector)->read($body, recipients: ['otro@cliente.example'])->recipient)->toBeNull();
});

it('prefers the recipients in their stored order (To before Cc) over the order of the report', function () {
    $body = "Delivery has failed to these recipients: copia@segurex.com\r\nOriginal message:\r\nTo: pagos@cliente.example\r\nCc: copia@segurex.com";

    expect((new BounceDetector)->read($body, recipients: ['pagos@cliente.example', 'copia@segurex.com'])->recipient)->toBe('pagos@cliente.example');
});

it('guesses the failed address when the recipients are unknown', function () {
    $body = 'From segurex.info@segurex.com to pagos@cliente.example: 550 5.1.1 via postmaster@cliente.example';

    expect((new BounceDetector)->read($body, ignore: ['segurex.info@segurex.com'])->recipient)->toBe('pagos@cliente.example');
});

it('finds the Message-IDs quoted in a text', function () {
    $text = "Message-ID: <abc123@outlook.com>\nIn-Reply-To: <abc123@outlook.com>\nTo: <pagos@cliente.example>\nnot <an id>";

    expect(MessageIds::in($text))->toBe(['<abc123@outlook.com>', '<pagos@cliente.example>']);
});
