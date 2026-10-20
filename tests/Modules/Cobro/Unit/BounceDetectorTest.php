<?php

use App\Modules\Cobro\Domain\Mail\BounceDetector;
use App\Modules\Cobro\Domain\Mail\MessageIds;

it('tells delivery reports from replies', function (string $from, string $subject, bool $bounce) {
    expect((new BounceDetector)->isBounce($from, $subject))->toBe($bounce);
})->with([
    'Exchange system sender' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com', 'Undeliverable: Facturas vencidas', true],
    'postmaster' => ['postmaster@cliente.example', 'Aviso', true],
    'mailer-daemon' => ['MAILER-DAEMON@mx.cliente.example', 'Returned mail: see transcript for details', true],
    'English subject' => ['someone@cliente.example', 'Undelivered Mail Returned to Sender', true],
    'failure DSN from an ordinary sender' => ['notificaciones@cliente.example', 'Delivery Status Notification (Failure)', true],
    'Gmail DSN' => ['mailer-daemon@googlemail.com', 'Delivery Status Notification (Failure)', true],
    'Spanish subject' => ['sistema@cliente.example', 'No se puede entregar: Facturas vencidas', true],
    'human reply' => ['tesoreria@cliente.example', 'RE: Facturas vencidas', false],
    'out of office' => ['tesoreria@cliente.example', 'Respuesta automática: Facturas vencidas', false],
    'Exchange delay' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com', 'Delivery delayed: Facturas vencidas', false],
    'Gmail delay' => ['mailer-daemon@googlemail.com', 'Delivery Status Notification (Delay)', false],
    'Spanish delay' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com', 'Entrega retrasada: Facturas vencidas', false],
    'delivery receipt' => ['MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com', 'Delivered: Facturas vencidas', false],
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

it('guesses the failed address when the recipients are unknown', function () {
    $body = 'From segurex.info@segurex.com to pagos@cliente.example: 550 5.1.1 via postmaster@cliente.example';

    expect((new BounceDetector)->read($body, ignore: ['segurex.info@segurex.com'])->recipient)->toBe('pagos@cliente.example');
});

it('finds the Message-IDs quoted in a text', function () {
    $text = "Message-ID: <abc123@outlook.com>\nIn-Reply-To: <abc123@outlook.com>\nTo: <pagos@cliente.example>\nnot <an id>";

    expect(MessageIds::in($text))->toBe(['<abc123@outlook.com>', '<pagos@cliente.example>']);
});
