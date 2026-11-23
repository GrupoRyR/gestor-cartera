<?php

use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\FakeAvisoService;

beforeEach(function () {
    $this->avisos = new FakeAvisoService;
});

it('records the sent notices', function () {
    $this->avisos->assertNothingSent();

    $first = new Aviso([4, 7], 'cartera.compromiso', 'Pago parcial recibido', key: 'commitment:1:partial');
    $second = new Aviso([7], 'core.prueba', 'Hola');
    $this->avisos->send($first);
    $this->avisos->send($second);

    $this->avisos->assertSent('cartera.compromiso');
    $this->avisos->assertSent('cartera.compromiso', 4);
    $this->avisos->assertSent('core.prueba', 7);

    expect($this->avisos->sent())->toBe([$first, $second])
        ->and(fn () => $this->avisos->assertSent('core.prueba', 4))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->avisos->assertSent('core.otro'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->avisos->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('resolves the open notices sent with a key, once', function () {
    $this->avisos->send(new Aviso([4, 7], 'cartera.compromiso', 'Pago parcial', key: 'commitment:1:partial'));
    $this->avisos->send(new Aviso([4], 'cartera.compromiso', 'Pago parcial', key: 'commitment:2:partial'));

    expect(fn () => $this->avisos->assertResolved('commitment:1:partial'))->toThrow(AssertionFailedError::class)
        ->and($this->avisos->resolve('commitment:1:partial'))->toBe(2)
        ->and($this->avisos->resolve('commitment:1:partial'))->toBe(0)
        ->and($this->avisos->resolve('commitment:9:partial'))->toBe(0)
        ->and($this->avisos->resolvedKeys())->toBe(['commitment:1:partial', 'commitment:1:partial', 'commitment:9:partial']);

    $this->avisos->assertResolved('commitment:1:partial');
    $this->avisos->send(new Aviso([5], 'cartera.compromiso', 'Pago parcial', key: 'commitment:1:partial'));

    expect($this->avisos->resolve('commitment:1:partial'))->toBe(1)
        ->and($this->avisos->resolve('commitment:2:partial'))->toBe(1);
});

it('links a notice like the real service', function () {
    $this->avisos->linkUsing('cartera.compromiso', fn (Notice $notice): ?string => null);
    $this->avisos->linkUsing('cartera.compromiso', fn (Notice $notice): string => "/compromisos/{$notice->subjectId}");
    $this->avisos->linkUsing('cartera.compromiso', fn (Notice $notice): string => '/otra');

    $notice = fn (string $kind): Notice => new Notice(
        id: 1,
        userId: 4,
        company: null,
        kind: $kind,
        title: 'Pago parcial',
        body: null,
        subjectType: 'commitment',
        subjectId: 12,
        key: null,
        data: [],
        readAt: null,
        resolvedAt: null,
        createdAt: CarbonImmutable::now(),
    );

    expect($this->avisos->link($notice('cartera.compromiso')))->toBe('/compromisos/12')
        ->and($this->avisos->link($notice('core.prueba')))->toBeNull();
});
