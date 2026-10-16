<?php

use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Infrastructure\Activity\DatabaseGestionLogger;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use Tests\Modules\Core\Support\ActivityRecords;
use Tests\Modules\Core\Support\CarteraRecords;
use Tests\Modules\Core\Support\CoreUsers;
use Tests\Modules\Core\Support\TeamUsers;

/*
 * Ana is an asesor in both companies: SGX-12 and SDC-40 are hers, SGX-20
 * belongs to nobody. She works in SGX until a test switches her company.
 */
beforeEach(function () {
    $this->ana = CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX, CompanyCode::SDC);
    $this->ana->update(['name' => 'Ana Gómez', 'email' => 'ana.gomez@segurex.com']);

    TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $this->ana);
    TeamUsers::advisor(CompanyCode::SGX, 20, 'Diana Duque');
    TeamUsers::advisor(CompanyCode::SDC, 40, 'Juliana Restrepo', $this->ana);

    $this->agro = CarteraRecords::client('Agro Arango', ['advisor_code' => 12]);
    $this->bodega = CarteraRecords::client('Bodega Bedoya', ['advisor_code' => 20]);
    $this->equipos = CarteraRecords::client('Equipos Echeverri', ['company' => CompanyCode::SDC, 'advisor_code' => 40]);
});

it('is the GestionLogger of the container', function () {
    expect(app(GestionLogger::class))->toBeInstanceOf(DatabaseGestionLogger::class);
});

it('logs an entry by the signed-in user', function () {
    $this->travelTo(CarteraRecords::at('2026-10-15 09:30:00'));
    $this->actingAs($this->ana);

    $activity = ActivityRecords::logger()->log(new NewActivity(
        clientId: $this->agro->id,
        type: ActivityType::Call,
        summary: 'Llamada a tesorería',
        details: 'Pide copia de la factura 104233.',
        result: 'Promete pagar el viernes',
    ));

    $row = ActivityRecord::query()->sole();

    expect($activity)->toEqual(new Activity(
        id: $row->id,
        clientId: $this->agro->id,
        clientName: 'Agro Arango',
        type: ActivityType::Call,
        summary: 'Llamada a tesorería',
        details: 'Pide copia de la factura 104233.',
        result: 'Promete pagar el viernes',
        user: new UserRef($this->ana->id, 'Ana Gómez', 'ana.gomez@segurex.com'),
        occurredAt: CarteraRecords::at('2026-10-15 09:30:00'),
    ))
        ->and($row->company)->toBe(CompanyCode::SGX)
        ->and($row->user_id)->toBe($this->ana->id)
        ->and(ActivityRecords::logger()->forClient($this->agro->id))->toEqual([$activity]);
});

it('logs as the system when no one is signed in, for any client', function () {
    $occurredAt = CarteraRecords::at('2026-10-14 18:00:00');

    $activity = ActivityRecords::logger()->log(new NewActivity(
        clientId: $this->bodega->id,
        type: ActivityType::EmailSent,
        summary: 'Correo de cobro semanal',
        occurredAt: $occurredAt,
    ));

    expect($activity->user)->toBeNull()
        ->and($activity->clientName)->toBe('Bodega Bedoya')
        ->and($activity->occurredAt->equalTo($occurredAt))->toBeTrue()
        ->and(ActivityRecord::query()->sole()->user_id)->toBeNull();
});

it('refuses a client outside the scope of the signed-in user', function () {
    $this->actingAs($this->ana);

    expect(fn () => ActivityRecords::logger()->log(new NewActivity($this->bodega->id, ActivityType::Note, 'Nota')))
        ->toThrow(RecordNotFound::class, "No client with id {$this->bodega->id} exists in the current scope.")
        ->and(ActivityRecord::query()->count())->toBe(0);
});

it('refuses a client that does not exist or was deleted', function (bool $signedIn) {
    $this->agro->delete();

    if ($signedIn) {
        $this->actingAs($this->ana);
    }

    foreach ([$this->agro->id, 999] as $clientId) {
        expect(fn () => ActivityRecords::logger()->log(new NewActivity($clientId, ActivityType::Note, 'Nota')))
            ->toThrow(RecordNotFound::class);
    }

    expect(ActivityRecord::query()->count())->toBe(0);
})->with([
    'signed in' => [true],
    'as the system' => [false],
]);

it('logs on the clients of every company the user may work in, not only the active one', function () {
    $this->actingAs($this->ana);

    $activity = ActivityRecords::logger()->log(new NewActivity($this->equipos->id, ActivityType::Note, 'Nota de SDC'));

    expect(app(CurrentCompany::class)->active()?->code)->toBe(CompanyCode::SGX)
        ->and($activity->clientName)->toBe('Equipos Echeverri')
        ->and(ActivityRecord::query()->sole()->company)->toBe(CompanyCode::SDC)
        ->and(ActivityRecords::summaries(ActivityRecords::logger()->forClient($this->equipos->id)))->toBe(['Nota de SDC']);
});

it('moves the last activity of the client forward only', function () {
    $this->actingAs($this->ana);
    $log = fn (string $at) => ActivityRecords::logger()->log(
        new NewActivity($this->agro->id, ActivityType::Note, "Nota de las {$at}", occurredAt: CarteraRecords::at("2026-10-15 {$at}")),
    );

    $log('10:00:00');
    expect($this->agro->refresh()->last_activity_at?->toDateTimeString())->toBe('2026-10-15 10:00:00');

    $log('08:00:00');
    expect($this->agro->refresh()->last_activity_at?->toDateTimeString())->toBe('2026-10-15 10:00:00');

    $log('11:15:00');
    expect($this->agro->refresh()->last_activity_at?->toDateTimeString())->toBe('2026-10-15 11:15:00');
});

it('leaves the last activity alone for entries of the system', function (?string $author, ActivityType $type) {
    if ($author !== null) {
        $this->actingAs($this->ana);
    }

    ActivityRecords::logger()->log(new NewActivity($this->agro->id, $type, 'Automática'));

    expect($this->agro->refresh()->last_activity_at)->toBeNull();
})->with([
    'written by the system' => [null, ActivityType::EmailSent],
    'of type System' => ['ana', ActivityType::System],
]);

it('reads the log of a client newest first, without removed entries', function () {
    ActivityRecords::entry($this->agro, 'Primera', '2026-10-13 08:00:00');
    ActivityRecords::entry($this->agro, 'Cuarta', '2026-10-15 09:00:00');
    ActivityRecords::entry($this->agro, 'Tercera', '2026-10-14 12:00:00', $this->ana);
    ActivityRecords::entry($this->agro, 'Quinta', '2026-10-15 09:00:00');
    ActivityRecords::entry($this->agro, 'Eliminada', '2026-10-16 08:00:00')->delete();
    ActivityRecords::entry($this->equipos, 'De otro cliente', '2026-10-15 10:00:00');
    $this->actingAs($this->ana);

    $log = ActivityRecords::logger()->forClient($this->agro->id);

    expect(ActivityRecords::summaries($log))->toBe(['Quinta', 'Cuarta', 'Tercera', 'Primera'])
        ->and($log[2]->user)->toEqual(new UserRef($this->ana->id, 'Ana Gómez', 'ana.gomez@segurex.com'))
        ->and($log[0]->user)->toBeNull()
        ->and(ActivityRecords::summaries(ActivityRecords::logger()->forClient($this->agro->id, 2)))->toBe(['Quinta', 'Cuarta']);
});

it('reads nothing for a client that is missing, deleted or out of scope', function () {
    ActivityRecords::entry($this->bodega, 'Ajena', '2026-10-15 09:00:00');
    ActivityRecords::entry($this->agro, 'Propia', '2026-10-15 09:00:00');

    expect(ActivityRecords::logger()->forClient($this->agro->id))->toBe([]);

    $this->actingAs($this->ana);
    $this->agro->delete();

    expect(ActivityRecords::logger()->forClient($this->bodega->id))->toBe([])
        ->and(ActivityRecords::logger()->forClient($this->agro->id))->toBe([])
        ->and(ActivityRecords::logger()->forClient(999))->toBe([]);
});

it('reads the recent entries of the clients in the scope of the active company', function () {
    ActivityRecords::entry($this->agro, 'Agro temprano', '2026-10-15 08:00:00');
    ActivityRecords::entry($this->equipos, 'Equipos', '2026-10-15 09:00:00');
    ActivityRecords::entry($this->agro, 'Agro tarde', '2026-10-15 10:00:00');
    ActivityRecords::entry($this->bodega, 'Bodega', '2026-10-15 11:00:00');
    ActivityRecords::entry($this->agro, 'Agro eliminada', '2026-10-15 12:00:00')->delete();
    $this->actingAs($this->ana);

    expect(ActivityRecords::summaries(ActivityRecords::logger()->recent()))->toBe(['Agro tarde', 'Agro temprano']);

    CarteraRecords::switchTo(null);

    expect(ActivityRecords::summaries(ActivityRecords::logger()->recent()))->toBe(['Agro tarde', 'Equipos', 'Agro temprano'])
        ->and(ActivityRecords::summaries(ActivityRecords::logger()->recent(1)))->toBe(['Agro tarde']);
});

it('reads no recent entries for guests and users who see no client', function (?Role $role) {
    ActivityRecords::entry($this->agro, 'Agro', '2026-10-15 08:00:00');

    if ($role !== null) {
        $this->actingAs(CoreUsers::create($role, CompanyCode::SGX));
    }

    expect(ActivityRecords::logger()->recent())->toBe([]);
})->with([
    'guest' => [null],
    'treasury' => [Role::Treasury],
]);

it('refuses a limit below 1', function () {
    $this->actingAs($this->ana);

    expect(fn () => ActivityRecords::logger()->forClient($this->agro->id, 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ActivityRecords::logger()->recent(0))->toThrow(InvalidArgumentException::class);
});

it('keeps the metadata as given', function () {
    $metadata = [
        'commitment_id' => 12,
        'internet_message_id' => '<a1b2c3@segurex.com>',
        'amount' => 1500000.5,
        'automatic' => false,
        'note' => null,
    ];
    $this->actingAs($this->ana);

    ActivityRecords::logger()->log(new NewActivity($this->agro->id, ActivityType::Commitment, 'Compromiso', metadata: $metadata));

    expect(ActivityRecords::logger()->forClient($this->agro->id)[0]->metadata)->toEqual($metadata)
        ->and(ActivityRecord::query()->sole()->metadata)->toEqual($metadata);
});
