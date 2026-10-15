<?php

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\FakeGestionLogger;
use Tests\Fakes\Make;

/**
 * @param  list<Activity>  $activities
 * @return list<string>
 */
function activitySummaries(array $activities): array
{
    return array_map(fn (Activity $activity): string => $activity->summary, $activities);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 11:00:00'));

    $this->logger = (new FakeGestionLogger)
        ->knowClient(10, 'Ferretería El Tornillo SAS')
        ->knowClient(20, 'Distribuidora Andina SA');
});

it('logs activities as the acting user at the current time', function () {
    $activity = $this->logger->log(new NewActivity(
        clientId: 10,
        type: ActivityType::Call,
        summary: 'Llamada a tesorería',
        result: 'Promete pagar',
        metadata: ['phone' => '6015550123'],
    ));

    expect($activity->id)->toBe(1)
        ->and($activity->clientName)->toBe('Ferretería El Tornillo SAS')
        ->and($activity->type)->toBe(ActivityType::Call)
        ->and($activity->result)->toBe('Promete pagar')
        ->and($activity->user)->toEqual(new UserRef(1, 'Usuario de prueba', 'prueba@example.com'))
        ->and($activity->occurredAt->toDateTimeString())->toBe('2026-10-15 11:00:00')
        ->and($activity->metadata)->toBe(['phone' => '6015550123']);
});

it('keeps the given time and logs as the system without a user', function () {
    $activity = $this->logger->actingAs(null)->log(new NewActivity(
        clientId: 10,
        type: ActivityType::System,
        summary: 'Cartera cargada',
        occurredAt: CarbonImmutable::parse('2026-10-14 07:00'),
    ));

    expect($activity->user)->toBeNull()
        ->and($activity->occurredAt->toDateTimeString())->toBe('2026-10-14 07:00:00');
});

it('uses a placeholder name for unknown clients when it has no cartera', function () {
    expect((new FakeGestionLogger)->log(new NewActivity(5, ActivityType::Note, 'Nota'))->clientName)
        ->toBe('Cliente 5');
});

it('resolves clients through the cartera fake and honours its scope', function () {
    $mine = Make::client(name: 'Cliente propio', advisor: Make::advisor(code: 22));
    $other = Make::client(name: 'Cliente ajeno', advisor: Make::advisor(code: 33));
    $cartera = (new FakeCarteraRepository)
        ->addClient(Make::clientDetail($mine))
        ->addClient(Make::clientDetail($other))
        ->restrictToAdvisor(22);
    $logger = new FakeGestionLogger($cartera);
    $logger->add(Make::activity(clientId: $other->id, clientName: 'Cliente ajeno', summary: 'Ajena'));

    expect($logger->log(new NewActivity($mine->id, ActivityType::Note, 'Propia'))->clientName)->toBe('Cliente propio')
        ->and(fn () => $logger->log(new NewActivity($other->id, ActivityType::Note, 'Ajena')))->toThrow(RecordNotFound::class)
        ->and(fn () => $logger->log(new NewActivity(999_999, ActivityType::Note, 'Nadie')))->toThrow(RecordNotFound::class)
        ->and($logger->forClient($other->id))->toBe([])
        ->and(activitySummaries($logger->recent()))->toBe(['Propia']);
});

it('reads a client log and the recent activity newest first', function () {
    $this->logger->log(new NewActivity(10, ActivityType::Note, 'Primera', occurredAt: CarbonImmutable::parse('2026-10-01')));
    $this->logger->log(new NewActivity(20, ActivityType::Call, 'Otra'));
    $this->logger->log(new NewActivity(10, ActivityType::Note, 'Segunda', occurredAt: CarbonImmutable::parse('2026-10-05')));
    $this->logger->log(new NewActivity(10, ActivityType::Note, 'Tercera'));

    expect(activitySummaries($this->logger->forClient(10)))->toBe(['Tercera', 'Segunda', 'Primera'])
        ->and(activitySummaries($this->logger->forClient(10, limit: 2)))->toBe(['Tercera', 'Segunda'])
        ->and(activitySummaries($this->logger->recent(3)))->toBe(['Tercera', 'Otra', 'Segunda'])
        ->and(fn () => $this->logger->recent(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->logger->forClient(10, 0))->toThrow(InvalidArgumentException::class);
});

it('asserts logged activities', function () {
    $this->logger->add(Make::activity(clientId: 10, type: ActivityType::Call));

    $this->logger->assertNothingLogged();
    expect(fn () => $this->logger->assertLogged(ActivityType::Call))->toThrow(AssertionFailedError::class);

    $this->logger->log(new NewActivity(10, ActivityType::DiscountOffered, 'Descuento del 5 %'));

    $this->logger->assertLogged(ActivityType::DiscountOffered);
    $this->logger->assertLogged(ActivityType::DiscountOffered, clientId: 10);
    expect(fn () => $this->logger->assertLogged(ActivityType::DiscountOffered, clientId: 20))->toThrow(AssertionFailedError::class)
        ->and(fn () => $this->logger->assertNothingLogged())->toThrow(AssertionFailedError::class)
        ->and($this->logger->logged())->toHaveCount(1);
});
