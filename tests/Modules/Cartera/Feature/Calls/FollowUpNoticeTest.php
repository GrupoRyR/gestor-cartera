<?php

use App\Models\User;
use App\Modules\Cartera\Application\Calls\SendFollowUpNotices;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Notices\EloquentAvisoService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

/*
 * cartera:avisos-seguimiento rings the bell of whoever logged a call on the
 * day they agreed to call the client again.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));

    $this->fakes = ContractFakes::install($this->app);
    $this->client = CarteraWorld::client($this->fakes, name: 'Agro Arango SAS', company: CompanyCode::SDC);
    $this->caller = CarteraUsers::analyst();
});

/**
 * A call of $caller (the test's analyst when null) to the test's client,
 * on 10/11/2026 with Juan Pérez, to be followed up on $day.
 *
 * @param  array<string, mixed>  $attributes
 */
function followUpCall(string $day, ?User $caller = null, array $attributes = []): CallRecord
{
    return CallRecord::factory()
        ->forClient(test()->client->id, CompanyCode::SDC)
        ->followUpOn(CarbonImmutable::parse($day))
        ->create([
            'user_id' => ($caller ?? test()->caller)->id,
            'occurred_at' => CarbonImmutable::parse('2026-11-10 15:30:00', 'America/Bogota'),
            'contact_name' => 'Juan Pérez',
            ...$attributes,
        ]);
}

/**
 * @return array{sent: int, skipped: int, failed: int}
 */
function sendFollowUps(): array
{
    return app(SendFollowUpNotices::class)->run();
}

/**
 * A «cartera.call.follow_up» notice as Core stores it.
 */
function followUpNotice(?string $subjectType = 'client', ?int $subjectId = 41): Notice
{
    return new Notice(
        id: 1,
        userId: 1,
        company: 'SDC',
        kind: 'cartera.call.follow_up',
        title: 'Agro Arango SAS: hoy toca volver a llamar',
        body: 'Lo acordaste en la llamada del 10/11/2026 con Juan Pérez.',
        subjectType: $subjectType,
        subjectId: $subjectId,
        key: "cartera.call.follow_up:{$subjectId}",
        data: ['call_id' => 7],
        readAt: null,
        resolvedAt: null,
        createdAt: CarbonImmutable::now(),
    );
}

describe('sending', function () {
    it('rings the caller on the day of the follow-up', function () {
        $call = followUpCall('2026-11-20');

        expect(sendFollowUps())->toBe(['sent' => 1, 'skipped' => 0, 'failed' => 0]);

        $this->fakes->avisos->assertSent('cartera.call.follow_up', $this->caller->id);
        $aviso = $this->fakes->avisos->sent()[0];

        expect($aviso->recipients)->toBe([$this->caller->id])
            ->and($aviso->key)->toBe("cartera.call.follow_up:{$this->client->id}")
            ->and($aviso->company)->toBe('SDC')
            ->and($aviso->subjectType)->toBe('client')
            ->and($aviso->subjectId)->toBe($this->client->id)
            ->and($aviso->title)->toBe('Agro Arango SAS: hoy toca volver a llamar')
            ->and($aviso->body)->toBe('Lo acordaste en la llamada del 10/11/2026 con Juan Pérez.')
            ->and($aviso->data)->toBe(['call_id' => $call->id])
            ->and($call->refresh()->follow_up_notified_at?->toDateTimeString())->toBe('2026-11-20 10:00:00')
            ->and($call->follow_up_closed_at)->toBeNull();
    });

    it('leaves the contact out of the body when the call had none', function () {
        followUpCall('2026-11-20', attributes: ['contact_name' => null]);

        sendFollowUps();

        expect($this->fakes->avisos->sent()[0]->body)->toBe('Lo acordaste en la llamada del 10/11/2026.');
    });

    it('shortens a client name that would not fit in the title', function () {
        $this->client = CarteraWorld::client($this->fakes, name: str_repeat('Distribuidora ', 15), company: CompanyCode::SDC);
        followUpCall('2026-11-20');

        sendFollowUps();

        $title = $this->fakes->avisos->sent()[0]->title;

        expect(mb_strlen($title))->toBeLessThanOrEqual(160)
            ->and($title)->toEndWith('…: hoy toca volver a llamar');
    });

    it('still rings once a follow-up whose day already passed', function () {
        followUpCall('2026-11-17');

        expect(sendFollowUps()['sent'])->toBe(1)
            ->and(sendFollowUps()['sent'])->toBe(0);

        expect($this->fakes->avisos->sent())->toHaveCount(1);
    });

    it('waits for the day of the follow-up', function () {
        $call = followUpCall('2026-11-21');

        expect(sendFollowUps())->toBe(['sent' => 0, 'skipped' => 0, 'failed' => 0]);

        $this->fakes->avisos->assertNothingSent();
        expect($call->refresh()->follow_up_notified_at)->toBeNull();
    });

    it('rings tomorrow’s follow-up after midnight in Bogotá', function () {
        followUpCall('2026-11-21');
        $this->travelTo(CarbonImmutable::parse('2026-11-21 07:00:00', 'America/Bogota'));

        expect(sendFollowUps()['sent'])->toBe(1);
    });

    it('skips follow-ups already rung or closed and calls without one', function () {
        followUpCall('2026-11-20', attributes: ['follow_up_notified_at' => CarbonImmutable::parse('2026-11-20 08:00:00')]);
        followUpCall('2026-11-20', attributes: ['follow_up_closed_at' => CarbonImmutable::parse('2026-11-19 08:00:00')]);
        CallRecord::factory()->forClient($this->client->id, CompanyCode::SDC)->create(['user_id' => $this->caller->id]);

        expect(sendFollowUps())->toBe(['sent' => 0, 'skipped' => 0, 'failed' => 0]);

        $this->fakes->avisos->assertNothingSent();
    });

    it('never rings twice when it runs again', function () {
        followUpCall('2026-11-20');
        followUpCall('2026-11-19');

        expect(sendFollowUps()['sent'])->toBe(2)
            ->and(sendFollowUps()['sent'])->toBe(0);

        expect($this->fakes->avisos->sent())->toHaveCount(2);
    });
});

describe('skipping', function () {
    it('skips without claiming a caller who can no longer ring', function (Closure $caller) {
        Log::spy();
        $call = followUpCall('2026-11-20', $caller());

        expect(sendFollowUps())->toBe(['sent' => 0, 'skipped' => 1, 'failed' => 0]);

        $this->fakes->avisos->assertNothingSent();
        expect($call->refresh()->follow_up_notified_at)->toBeNull();
        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message): bool => str_contains($message, 'follow-up'));
    })->with([
        'inactive' => [fn (): User => tap(CarteraUsers::analyst())->update(['is_active' => false])],
        'without cartera.manage' => [fn (): User => CarteraUsers::create(Role::SalesAdvisor)],
        'deleted' => [fn (): User => tap(CarteraUsers::analyst())->delete()],
    ]);

    it('rings a caller again once they may log calls', function () {
        $caller = tap(CarteraUsers::analyst())->update(['is_active' => false]);
        followUpCall('2026-11-20', $caller);

        expect(sendFollowUps()['skipped'])->toBe(1);

        $caller->update(['is_active' => true]);

        expect(sendFollowUps()['sent'])->toBe(1);
        $this->fakes->avisos->assertSent('cartera.call.follow_up', $caller->id);
    });

    it('skips without claiming the follow-up of a client that is gone', function () {
        $call = CallRecord::factory()
            ->forClient(999_998, CompanyCode::SDC)
            ->followUpOn(CarbonImmutable::parse('2026-11-20'))
            ->create(['user_id' => $this->caller->id]);

        expect(sendFollowUps())->toBe(['sent' => 0, 'skipped' => 1, 'failed' => 0]);

        $this->fakes->avisos->assertNothingSent();
        expect($call->refresh()->follow_up_notified_at)->toBeNull()
            ->and($call->follow_up_closed_at)->toBeNull();
    });

    it('logs an error and claims nothing without AvisoService', function () {
        Log::spy();
        $call = followUpCall('2026-11-20');
        $this->app->forgetInstance(AvisoService::class);
        unset($this->app[AvisoService::class]);

        expect(sendFollowUps())->toBe(['sent' => 0, 'skipped' => 0, 'failed' => 0]);

        $this->fakes->avisos->assertNothingSent();
        expect($call->refresh()->follow_up_notified_at)->toBeNull();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message): bool => str_contains($message, 'AvisoService'));
    });

    it('rolls the claim back when the bell fails, so the next run retries', function () {
        $call = followUpCall('2026-11-20');
        $this->app->instance(AvisoService::class, new class implements AvisoService
        {
            public function send(Aviso $aviso): void
            {
                throw new RuntimeException('The notices table is gone.');
            }

            public function resolve(string $key): int
            {
                return 0;
            }

            public function linkUsing(string $kind, Closure $resolver): void {}
        });

        expect(sendFollowUps())->toBe(['sent' => 0, 'skipped' => 0, 'failed' => 1])
            ->and($call->refresh()->follow_up_notified_at)->toBeNull();

        $this->app->instance(AvisoService::class, $this->fakes->avisos);

        expect(sendFollowUps()['sent'])->toBe(1);
    });
});

describe('the command', function () {
    it('says how many follow-ups it rang and skipped', function () {
        followUpCall('2026-11-20');
        followUpCall('2026-11-20', tap(CarteraUsers::analyst())->update(['is_active' => false]));

        $this->artisan('cartera:avisos-seguimiento')
            ->expectsOutput('Avisamos 1 seguimiento en la campana.')
            ->expectsOutput('Omitimos 1 seguimiento; el motivo quedó en el log.')
            ->assertSuccessful();
    });

    it('says when there was nothing to ring', function () {
        $this->artisan('cartera:avisos-seguimiento')
            ->expectsOutput('No hay seguimientos para avisar.')
            ->doesntExpectOutputToContain('Omitimos')
            ->assertSuccessful();
    });

    it('fails when a notice could not be sent', function () {
        followUpCall('2026-11-20');
        $this->app->instance(AvisoService::class, new class implements AvisoService
        {
            public function send(Aviso $aviso): void
            {
                throw new RuntimeException('The notices table is gone.');
            }

            public function resolve(string $key): int
            {
                return 0;
            }

            public function linkUsing(string $kind, Closure $resolver): void {}
        });

        $this->artisan('cartera:avisos-seguimiento')
            ->expectsOutput('No pudimos avisar 1 seguimiento; el error quedó en el log.')
            ->assertFailed();
    });

    it('runs every hour from 07:00 to 18:00 in Bogotá without overlapping', function () {
        // The scheduler reads the clock when it builds the schedule, as each schedule:run does.
        $events = function (): array {
            $this->app->forgetInstance(Schedule::class);

            return array_values(array_filter(
                app(Schedule::class)->events(),
                fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'cartera:avisos-seguimiento'),
            ));
        };

        expect($events())->toHaveCount(1)
            ->and($events()[0]->expression)->toBe('0 * * * *')
            ->and($events()[0]->timezone)->toBe('America/Bogota')
            ->and($events()[0]->withoutOverlapping)->toBeTrue()
            ->and($events()[0]->expiresAt)->toBe(10);

        $runsAt = function (string $time) use ($events): bool {
            $this->travelTo(CarbonImmutable::parse("2026-11-20 {$time}", 'America/Bogota'));

            return $events()[0]->filtersPass($this->app);
        };

        expect($runsAt('06:00:00'))->toBeFalse()
            ->and($runsAt('07:00:00'))->toBeTrue()
            ->and($runsAt('18:00:00'))->toBeTrue()
            ->and($runsAt('19:00:00'))->toBeFalse();
    });
});

describe('the link', function () {
    it('opens the client file with the call form for whoever may open Cartera', function () {
        $this->actingAs(CarteraUsers::viewer());

        expect(app(EloquentAvisoService::class)->link(followUpNotice()))
            ->toBe(route('cartera.clientes.show', ['client' => 41, 'registrar-llamada' => 1]));
    });

    it('passes for users without Cartera, guests and notices about something else', function () {
        $avisos = app(EloquentAvisoService::class);

        expect($avisos->link(followUpNotice()))->toBeNull();

        $this->actingAs(CarteraUsers::create(Role::SalesAdvisor));
        expect($avisos->link(followUpNotice()))->toBeNull();

        $this->actingAs(CarteraUsers::analyst());
        expect($avisos->link(followUpNotice(subjectType: 'commitment')))->toBeNull()
            ->and($avisos->link(followUpNotice(subjectId: null)))->toBeNull();
    });
});
