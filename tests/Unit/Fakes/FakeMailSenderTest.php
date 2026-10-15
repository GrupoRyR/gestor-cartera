<?php

use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\FakeMailSender;

/**
 * @param  list<string>  $to
 */
function fakeMail(array $to = ['pagos@cliente.com'], CompanyCode $company = CompanyCode::SGX, string $category = 'cobro_semanal'): OutgoingMail
{
    return new OutgoingMail(
        company: $company,
        to: $to,
        subject: 'Facturas vencidas',
        html: '<p>Hola</p>',
        clientId: 10,
        category: $category,
    );
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 17:00:00'));
    $this->sender = new FakeMailSender;
});

it('sends from the company mailbox with deterministic ids', function () {
    $first = $this->sender->send(fakeMail());
    $second = $this->sender->send(fakeMail(company: CompanyCode::SDC));

    expect($first->mailbox)->toBe('segurex.info@example.test')
        ->and($first->internetMessageId)->toBe('<fake-1@gestor-cartera.test>')
        ->and($first->conversationId)->toBe('fake-conversation-1')
        ->and($first->sentAt->toDateTimeString())->toBe('2026-10-14 17:00:00')
        ->and($second->mailbox)->toBe('derivados.info@derivadoscol.com')
        ->and($second->internetMessageId)->toBe('<fake-2@gestor-cartera.test>')
        ->and($this->sender->sent())->toHaveCount(2)
        ->and($this->sender->receipts())->toBe([$first, $second]);
});

it('fails the next send only', function () {
    $this->sender->failNextWith(new MailNotSent('Graph throttled the request.'));

    expect(fn () => $this->sender->send(fakeMail()))->toThrow(MailNotSent::class, 'throttled')
        ->and($this->sender->sent())->toBe([]);

    $receipt = $this->sender->send(fakeMail());

    expect($receipt->internetMessageId)->toBe('<fake-1@gestor-cartera.test>');
});

it('asserts sent mail', function () {
    $this->sender->assertNothingSent();
    expect(fn () => $this->sender->assertSent())->toThrow(AssertionFailedError::class);

    $this->sender->send(fakeMail(['Pagos@Cliente.com', 'otro@cliente.com']));
    $this->sender->send(fakeMail(['pagos@cliente.com'], category: 'prueba'));

    $this->sender->assertSent();
    $this->sender->assertSent(times: 2);
    $this->sender->assertSent(fn (OutgoingMail $mail): bool => $mail->category === 'prueba', times: 1);
    $this->sender->assertSentTo('pagos@cliente.com');
    $this->sender->assertSentTo('OTRO@cliente.com');

    expect(fn () => $this->sender->assertSent(times: 3))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->sender->assertSent(fn (OutgoingMail $mail): bool => $mail->category === 'alerta'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->sender->assertSentTo('nadie@cliente.com'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->sender->assertNothingSent())->toThrow(AssertionFailedError::class);
});
