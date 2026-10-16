<?php

use App\Modules\Asesores\Domain\Whatsapp\AccountMessage;
use App\Modules\Asesores\Domain\Whatsapp\PhoneNumber;
use App\Modules\Asesores\Domain\Whatsapp\WhatsappLink;
use App\Modules\Core\Contracts\Data\Contact;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

// --- the number ---------------------------------------------------------------

it('turns the way people write a Colombian mobile into the number WhatsApp wants', function (string $written) {
    expect(PhoneNumber::whatsapp($written))->toBe('573115550101');
})->with([
    'national, plain' => ['3115550101'],
    'national, with spaces' => ['311 555 0101'],
    'national, with punctuation' => ['(311) 555-0101'],
    'with the country code' => ['573115550101'],
    'with plus and spaces' => ['+57 311 555 0101'],
    'with 00' => ['0057 311 555 0101'],
    'as SAP stores it' => ['+57 311 555 0101 '],
]);

it('has no WhatsApp for a landline, a short number or nothing', function (string $written) {
    expect(PhoneNumber::whatsapp($written))->toBeNull();
})->with([
    'a Bogotá landline' => ['601 555 0123'],
    'a landline with the country code' => ['+57 601 555 0123'],
    'too short' => ['311 555'],
    'too long' => ['31155501011'],
    'words' => ['ext. 12 con Laura'],
    'empty' => [''],
    'blank' => ['  '],
]);

it('keeps the mobiles of other countries when the country code is written', function () {
    expect(PhoneNumber::whatsapp('+1 305 555 0101'))->toBe('13055550101')
        ->and(PhoneNumber::whatsapp('+593 99 123 4567'))->toBe('593991234567');
});

it('picks the first contact with a mobile, the primary one first', function () {
    $contacts = [
        new Contact('Sin teléfono', 'a@x.test', null, null, true),
        new Contact('Fijo', null, '601 555 0123', null, false),
        new Contact('Celular', null, '311 555 0101', null, false),
        new Contact('Otro celular', null, '320 555 0202', null, false),
    ];

    expect(PhoneNumber::firstWhatsapp($contacts))->toBe('573115550101')
        ->and(PhoneNumber::firstWhatsapp([new Contact('Fijo', null, '601 555 0123')]))->toBeNull()
        ->and(PhoneNumber::firstWhatsapp([]))->toBeNull();
});

// --- the link -----------------------------------------------------------------

it('opens the chat of the number with the message ready, or lets the advisor pick the contact', function () {
    expect(WhatsappLink::url('573115550101', 'Hola, ¿cómo está?'))->toBe('https://wa.me/573115550101?text=Hola%2C%20%C2%BFc%C3%B3mo%20est%C3%A1%3F')
        ->and(WhatsappLink::url(null, 'Hola'))->toBe('https://wa.me/?text=Hola');
});

// --- the message ----------------------------------------------------------------

function asesoresMessage(array $invoices, int $maxLines = 12): string
{
    return AccountMessage::build('Ferretería El Tornillo SAS', 'Segurex Latam', CarbonImmutable::parse('2026-10-16', 'America/Bogota'), $invoices, $maxLines);
}

it('writes the account statement the client will read', function () {
    $message = asesoresMessage([
        Make::invoice(documentNumber: '10231', dueAt: CarbonImmutable::parse('2026-07-08', 'America/Bogota'), balance: 5_000_000.0, daysOverdue: 100),
        Make::invoice(documentNumber: '10377', dueAt: CarbonImmutable::parse('2026-10-26', 'America/Bogota'), balance: 2_000_000.0, daysOverdue: -10),
        Make::invoice(documentNumber: '10300', dueAt: CarbonImmutable::parse('2026-10-15', 'America/Bogota'), balance: 300_000.0, daysOverdue: 1),
    ]);

    expect($message)->toBe(implode("\n", [
        'Buen día, Ferretería El Tornillo SAS.',
        '',
        'Le comparto el estado de su cuenta con Segurex Latam al 16/10/2026:',
        '',
        'Saldo total: $ 7.300.000',
        'Vencido: $ 5.300.000',
        '',
        '• Factura 10231 · vence 08/07/2026 · $ 5.000.000 (100 días de mora)',
        '• Factura 10300 · vence 15/10/2026 · $ 300.000 (1 día de mora)',
        '• Factura 10377 · vence 26/10/2026 · $ 2.000.000',
        '',
        'Quedo atento(a) para coordinar el pago. ¡Gracias!',
    ]));
});

it('says there is no overdue balance when nothing is late', function () {
    $message = asesoresMessage([Make::invoice(documentNumber: '1', balance: 1_000_000.0, daysOverdue: -5)]);

    expect($message)->toContain('Sin saldo vencido.')->not->toContain('Vencido:');
});

it('leaves credit notes and receipts out of the lines and the totals', function () {
    $message = asesoresMessage([
        Make::invoice(documentNumber: '1', balance: 1_000_000.0, daysOverdue: 10),
        Make::invoice(documentNumber: '2', balance: -400_000.0, daysOverdue: 10),
    ]);

    expect($message)->toContain('Saldo total: $ 1.000.000')->not->toContain('Factura 2');
});

it('lists the oldest invoices and counts the rest', function () {
    $invoices = array_map(fn (int $n) => Make::invoice(documentNumber: (string) $n, balance: 100_000.0, daysOverdue: $n), range(1, 14));
    $message = asesoresMessage($invoices, 12);

    expect(substr_count($message, '• Factura'))->toBe(12)
        ->and($message)->toContain('Factura 14 ')->not->toContain('Factura 2 ')
        ->and($message)->toContain('• y 2 facturas más');

    expect(asesoresMessage(array_map(fn (int $n) => Make::invoice(documentNumber: (string) $n, balance: 100_000.0, daysOverdue: $n), range(1, 13)), 12))
        ->toContain('• y 1 factura más');
});

it('writes plain spaces, so the message does not carry hidden characters', function () {
    expect(asesoresMessage([Make::invoice(balance: 1_000_000.0, daysOverdue: 3)]))->not->toContain("\u{00A0}");
});

it('confirms there is nothing to pay when the account has no balance', function () {
    expect(asesoresMessage([]))->toBe(implode("\n", [
        'Buen día, Ferretería El Tornillo SAS.',
        '',
        'Le confirmo que al 16/10/2026 su cuenta con Segurex Latam no tiene saldo pendiente. ¡Gracias!',
    ]));
});
