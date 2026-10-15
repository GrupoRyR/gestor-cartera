<?php

use App\Modules\Core\Contracts\Data\InboundMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\FakeCurrentCompany;
use Tests\Fakes\FakeMailboxReader;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->mails = [
        Make::inboundMail(id: 'a', clientId: 10, receivedAt: CarbonImmutable::parse('2026-10-10 08:00'), read: true),
        Make::inboundMail(id: 'b', clientId: 10, receivedAt: CarbonImmutable::parse('2026-10-12 08:00'), isBounce: true, bounceReason: 'Buzón lleno'),
        Make::inboundMail(id: 'c', clientId: 20, receivedAt: CarbonImmutable::parse('2026-10-11 08:00')),
        Make::inboundMail(id: 'd', company: CompanyCode::SDC, clientId: null, receivedAt: CarbonImmutable::parse('2026-10-13 08:00')),
        Make::inboundMail(id: 'e', clientId: 10, receivedAt: CarbonImmutable::parse('2026-10-11 08:00')),
    ];
});

it('lists the mail of a client newest first', function () {
    $reader = (new FakeMailboxReader)->add(...$this->mails);
    $ids = fn (array $mails): array => array_map(fn (InboundMail $mail): string => $mail->id, $mails);

    expect($ids($reader->forClient(10)))->toBe(['b', 'e', 'a'])
        ->and($ids($reader->forClient(10, limit: 1)))->toBe(['b'])
        ->and($reader->forClient(99))->toBe([])
        ->and(fn () => $reader->forClient(10, limit: 0))->toThrow(InvalidArgumentException::class);
});

it('counts unread mail per company or in total', function () {
    $reader = (new FakeMailboxReader)->add(...$this->mails);

    expect($reader->unreadCount())->toBe(4)
        ->and($reader->unreadCount(CompanyCode::SGX))->toBe(3)
        ->and($reader->unreadCount(CompanyCode::SDC))->toBe(1);
});

it('counts unread mail of the companies in scope when it knows the current company', function () {
    $current = new FakeCurrentCompany(active: CompanyCode::SDC);
    $reader = (new FakeMailboxReader($current))->add(...$this->mails);

    expect($reader->unreadCount())->toBe(1);

    $current->switchTo(null);

    expect($reader->unreadCount())->toBe(4);
});
