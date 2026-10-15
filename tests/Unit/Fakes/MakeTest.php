<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:30:00'));
});

it('builds companies with their official parameters', function () {
    $sdc = Make::company(CompanyCode::SDC);

    expect($sdc->name)->toBe('Soluciones y Derivados')
        ->and($sdc->graceDays)->toBe(5)
        ->and($sdc->palette)->toEqual(BrandPalette::official(CompanyCode::SDC))
        ->and(Make::company()->graceDays)->toBe(15)
        ->and(Make::company(graceDays: 20)->graceDays)->toBe(20);
});

it('gives every built record a new id', function () {
    $first = Make::client();
    $second = Make::client();

    expect($second->id)->toBeGreaterThan($first->id)
        ->and($first->code)->not->toBe($second->code)
        ->and($first->taxId)->not->toBe($second->taxId)
        ->and(Make::client(id: 77)->id)->toBe(77);
});

it('accepts named overrides', function () {
    $client = Make::client(name: 'Otra SAS', status: ClientStatus::Legal, balance: 10, company: CompanyCode::SDC);

    expect($client->name)->toBe('Otra SAS')
        ->and($client->status)->toBe(ClientStatus::Legal)
        ->and($client->balance)->toBe(10.0)
        ->and($client->company)->toBe(CompanyCode::SDC);
});

it('derives invoice dates from the days overdue', function () {
    $overdue = Make::invoice(daysOverdue: 10);
    $notDue = Make::invoice(daysOverdue: -5, balance: 200);

    expect($overdue->dueAt->toDateString())->toBe('2026-10-05')
        ->and($overdue->issuedAt->toDateString())->toBe('2026-09-05')
        ->and($overdue->balance)->toBe($overdue->amount)
        ->and($notDue->dueAt->toDateString())->toBe('2026-10-20')
        ->and($notDue->balance)->toBe(200.0);
});

it('gives a client file one primary contact unless told otherwise', function () {
    $client = Make::client();

    expect(Make::clientDetail($client)->summary)->toBe($client)
        ->and(Make::clientDetail()->emails())->toHaveCount(1)
        ->and(Make::clientDetail(contacts: [])->contacts)->toBe([]);
});

it('builds activities by a user unless they come from the system', function () {
    expect(Make::activity()->user)->not->toBeNull()
        ->and(Make::activity(type: ActivityType::System)->user)->toBeNull()
        ->and(Make::activity(bySystem: true)->user)->toBeNull();
});

it('builds the rest of the contract data', function () {
    $day = Make::dailyCollection(total: 300);

    expect(Make::commitment()->dueDate->toDateString())->toBe('2026-10-22')
        ->and(Make::loadRecord()->cutoffDate->toDateString())->toBe('2026-10-15')
        ->and(Make::inboundMail()->id)->not->toBe(Make::inboundMail()->id)
        ->and($day->date->toDateString())->toBe('2026-10-14')
        ->and($day->byBank)->toBe([Bank::Bancolombia->value => 300.0])
        ->and(Make::userRef()->email)->toContain('@')
        ->and(Make::contact()->isPrimary)->toBeFalse();
});
