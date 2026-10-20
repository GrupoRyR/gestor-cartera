<?php

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Core\Contracts\Enums\Dataset;
use Carbon\CarbonImmutable;
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
        ->and($stored->cambios)->toBe(['valor' => ['old' => '100.00', 'new' => '200.00']])
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
