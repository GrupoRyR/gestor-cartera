<?php

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| The module's audit trail (§10, §7.9): an insert-only comisiones_bitacora
| row per event, mirrored to Core's AuditLogger.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:30:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->trail = app(AuditTrail::class);
});

it('writes the event with the user, the period and the changes, and tells Core', function () {
    $user = User::factory()->create(['name' => 'Ana Gómez']);

    $record = $this->trail->record(
        'comisiones.budget.updated',
        subjectType: 'comisiones_presupuesto',
        subjectId: 7,
        changes: ['valor' => ['old' => '100.00', 'new' => '200.00']],
        context: ['slp_code' => 14],
        period: '2026-07',
        user: $user,
        reason: 'Ajuste de la meta',
    );

    $stored = AuditRecord::query()->sole();

    expect($stored->id)->toBe($record->id)
        ->and($stored->evento)->toBe('comisiones.budget.updated')
        ->and($stored->periodo)->toBe('2026-07')
        ->and($stored->user_id)->toBe($user->id)
        ->and($stored->usuario)->toBe('Ana Gómez')
        ->and($stored->sujeto_tipo)->toBe('comisiones_presupuesto')
        ->and($stored->sujeto_id)->toBe('7')
        // MySQL's JSON columns reorder keys: compare the pairs, not their order.
        ->and($stored->cambios)->toEqual(['valor' => ['old' => '100.00', 'new' => '200.00']])
        ->and($stored->contexto)->toBe(['slp_code' => 14])
        ->and($stored->motivo)->toBe('Ajuste de la meta')
        ->and($stored->created_at?->format('Y-m-d H:i'))->toBe('2026-08-03 09:30');

    $this->fakes->audit->assertRecorded('comisiones.budget.updated', fn (AuditEntry $entry) => $entry->subjectType === 'comisiones_presupuesto'
        && $entry->subjectId === 7
        && $entry->changes === ['valor' => ['old' => '100.00', 'new' => '200.00']]
        && $entry->context === ['slp_code' => 14, 'periodo' => '2026-07', 'motivo' => 'Ajuste de la meta']);
});

it('names the authenticated user, or the system when nobody is signed in', function () {
    $this->trail->record('comisiones.load.imported');

    $this->actingAs($user = User::factory()->create(['name' => 'Beto Ruiz']));
    $this->trail->record('comisiones.settlement.calculated', period: '2026-07');

    expect(AuditRecord::query()->orderBy('id')->get()->map(fn (AuditRecord $row) => [$row->user_id, $row->usuario])->all())
        ->toBe([[null, 'Sistema'], [$user->id, 'Beto Ruiz']]);
});

it('redacts keys that look like secrets, in any case and at any depth, as Core does', function () {
    $this->trail->record(
        'comisiones.parameter.updated',
        changes: ['Api_Token' => ['old' => 'viejo', 'new' => 'nuevo'], 'valor' => ['old' => '1', 'new' => '2']],
        context: ['conexion' => ['PASSWORD' => 'clave', 'host' => 'sap.example'], 'claves' => [['client_secret' => 'otro'], 'libre']],
    );

    $stored = AuditRecord::query()->sole();

    expect($stored->cambios)->toEqual(['Api_Token' => '[redacted]', 'valor' => ['old' => '1', 'new' => '2']])
        ->and($stored->contexto)->toEqual(['conexion' => ['PASSWORD' => '[redacted]', 'host' => 'sap.example'], 'claves' => [['client_secret' => '[redacted]'], 'libre']]);
});

it('refuses an event name Core would reject before writing anything', function () {
    expect(fn () => $this->trail->record('comisiones.cargado'))->toThrow(InvalidArgumentException::class)
        ->and(AuditRecord::query()->count())->toBe(0);
});

it('never updates or deletes an event', function () {
    $record = $this->trail->record('comisiones.load.imported');

    expect(fn () => $record->update(['evento' => 'comisiones.load.deleted']))->toThrow(LogicException::class)
        ->and(fn () => $record->delete())->toThrow(LogicException::class)
        ->and(AuditRecord::query()->sole()->evento)->toBe('comisiones.load.imported');
});

it('never updates an event, even without model events', function (string $method) {
    $record = $this->trail->record('comisiones.load.imported', changes: ['filas' => 2]);
    $record->evento = 'comisiones.load.deleted';
    $record->cambios = ['filas' => 0];

    $update = match ($method) {
        'withoutEvents' => fn () => AuditRecord::withoutEvents(fn () => $record->save()),
        default => fn () => $record->{$method}(),
    };

    expect($update)->toThrow(LogicException::class, 'insert-only');

    $stored = AuditRecord::query()->sole();

    expect($stored->evento)->toBe('comisiones.load.imported')
        ->and($stored->cambios)->toBe(['filas' => 2]);
})->with(['save', 'saveQuietly', 'withoutEvents']);

it('never deletes an event, even without model events', function (string $method) {
    $record = $this->trail->record('comisiones.load.imported');

    $delete = match ($method) {
        'destroy' => fn () => AuditRecord::destroy($record->id),
        'withoutEvents' => fn () => AuditRecord::withoutEvents(fn () => $record->delete()),
        default => fn () => $record->{$method}(),
    };

    expect($delete)->toThrow(LogicException::class, 'insert-only')
        ->and(AuditRecord::query()->whereKey($record->id)->exists())->toBeTrue();
})->with(['deleteQuietly', 'forceDelete', 'destroy', 'withoutEvents']);

it('refuses to remove for good a user who has events, and the event still names them', function () {
    $user = User::factory()->create(['name' => 'Ana Gómez']);
    $this->trail->record('comisiones.budget.updated', user: $user);

    expect(fn () => $user->forceDelete())->toThrow(QueryException::class);

    expect(User::withTrashed()->whereKey($user->id)->exists())->toBeTrue()
        ->and(AuditRecord::query()->sole()->user_id)->toBe($user->id);
});

it('still removes for good a user without events', function () {
    $user = User::factory()->create();

    $user->forceDelete();

    expect(User::withTrashed()->whereKey($user->id)->exists())->toBeFalse();
});

it('keeps the user link restricted and indexed', function () {
    $foreignKey = collect(Schema::getForeignKeys('comisiones_bitacora'))->sole(fn (array $key) => $key['columns'] === ['user_id']);

    expect($foreignKey['foreign_table'])->toBe('users')
        ->and(strtolower($foreignKey['on_delete']))->toBe('restrict')
        ->and(Schema::hasIndex('comisiones_bitacora', ['user_id', 'created_at']))->toBeTrue();
});

it('audits every staged load', function () {
    $load = (new SapBatches($this->fakes->ingesta))->send(Dataset::Sales, [SapBatches::sale(), SapBatches::sale(['linea' => 1])]);

    $this->fakes->audit->assertRecorded('comisiones.load.imported', fn (AuditEntry $entry) => $entry->context === [
        'dataset' => 'ventas',
        'load_id' => $load->id,
        'corte' => '2026-07-31',
        'filas' => 2,
        'insertadas' => 2,
        'actualizadas' => 0,
    ]);

    expect(AuditRecord::query()->sole()->usuario)->toBe('Sistema');
});
