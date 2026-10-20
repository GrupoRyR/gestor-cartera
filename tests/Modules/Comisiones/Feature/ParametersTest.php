<?php

use App\Models\User;
use App\Modules\Comisiones\Application\DefaultParameters;
use App\Modules\Comisiones\Application\Ports\CommissionParameters;
use App\Modules\Comisiones\Application\Ports\Settlements;
use App\Modules\Comisiones\Domain\Calculation\SettlementCalculator;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Parameters\Options\ComplianceScope;
use App\Modules\Comisiones\Domain\Settlement\Settlement;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Infrastructure\Parameters\EloquentCommissionParameters;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterChangeRefused;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterMapper;
use App\Modules\Comisiones\Infrastructure\Parameters\ParameterVersions;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;

/*
| The parameter book (§4, §7.2): seeded once with DefaultParameters, then
| edited only as new versions with their validity, motive and audit.
*/

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->parameters = app(CommissionParameters::class);
    $this->versions = app(ParameterVersions::class);
    $this->editor = User::factory()->create(['name' => 'Ana Gómez']);
    $this->july = Period::of(2026, 7);
    $this->august = Period::of(2026, 8);

    // The stored data of code 14, with a new sale rate.
    $this->code14 = fn (string $saleRate = '1') => [
        'nombre' => 'Mónica Rivera',
        'persona' => ['id' => 'monica-rivera', 'nombre' => 'Mónica Rivera'],
        'tasa_venta' => $saleRate,
        'tasa_recaudo' => '0.75',
        'cumplimiento_minimo' => '90',
        'alcance' => 'regular',
        'usa_tramos' => true,
    ];
});

it('is bound to the stored book, seeded with the default parameters', function () {
    expect($this->parameters)->toBeInstanceOf(EloquentCommissionParameters::class)
        ->and($this->parameters->book())->toEqual(DefaultParameters::book('2026-01-01'))
        ->and($this->parameters->book()->forPeriod($this->july))->toEqual(DefaultParameters::book()->forPeriod($this->july))
        ->and(ParameterRecord::query()->whereNull('created_by')->count())->toBe(ParameterRecord::query()->count());
});

it('maps every kind of version to its stored data and back', function () {
    $mapper = new ParameterMapper;
    $book = DefaultParameters::book();
    $records = $mapper->fromBook($book);

    expect(array_values(array_unique(array_column($records, 'tipo'))))->toBe(['codigo', 'tramos', 'equipo', 'derivada'])
        ->and($mapper->book($records))->toEqual($book);

    $options = $mapper->toRecord($mapper->fromRecord('opciones', 'opciones', '2026-09-01', null, [
        'base_venta' => 'con_iva',
        'iva_venta' => '19',
        'notas_credito' => 'excluir',
        'cruces_en_derivadas' => true,
        'cruces_en_directores' => false,
        'ubicacion_director' => 'codigo_asociado',
        'vendedor_cruce' => 'fecha_factura',
        'alcance_cumplimiento' => 'persona',
        'moneda' => 'convertir',
    ]));

    expect($options['datos']['alcance_cumplimiento'])->toBe('persona')
        ->and($options['vigente_desde'])->toBe('2026-09-01')
        ->and($options['vigente_hasta'])->toBeNull();
});

it('adds a version from a date: the one in force ends the day before', function () {
    $record = $this->versions->add('codigo', '14', '2026-08-01', ($this->code14)('1'), 'Nueva tasa de venta acordada', $this->editor);

    expect($record->vigente_desde->format('Y-m-d'))->toBe('2026-08-01')
        ->and($record->vigente_hasta)->toBeNull()
        ->and($record->created_by)->toBe($this->editor->id)
        ->and($record->motivo)->toBe('Nueva tasa de venta acordada');

    $book = $this->parameters->book();

    expect($book->forPeriod($this->july)->code(14)?->saleRate->percent())->toBe('0.75')
        ->and($book->forPeriod($this->august)->code(14)?->saleRate->percent())->toBe('1')
        ->and(ParameterRecord::query()->where('tipo', 'codigo')->where('clave', '14')->orderBy('vigente_desde')->get()
            ->map(fn (ParameterRecord $row) => [$row->vigente_desde->format('Y-m-d'), $row->vigente_hasta?->format('Y-m-d')])->all())
        ->toBe([['2026-01-01', '2026-07-31'], ['2026-08-01', null]]);

    $this->fakes->audit->assertRecorded('comisiones.parameter.version_added', fn (AuditEntry $entry) => $entry->subjectId === $record->id
        && $entry->changes['datos']['old']['tasa_venta'] === '0.75'
        && $entry->changes['datos']['new']['tasa_venta'] === '1'
        && $entry->changes['vigente_hasta_anterior'] === ['old' => null, 'new' => '2026-07-31']
        && $entry->context === ['tipo' => 'codigo', 'clave' => '14', 'vigente_desde' => '2026-08-01', 'motivo' => 'Nueva tasa de venta acordada']);

    expect(AuditRecord::query()->sole()->usuario)->toBe('Ana Gómez');
});

it('replaces a version that starts the same day: it is a correction', function () {
    $this->versions->add('codigo', '14', '2026-08-01', ($this->code14)('1'), 'Nueva tasa', $this->editor);
    $this->versions->add('codigo', '14', '2026-08-01', ($this->code14)('0.9'), 'Corrige la tasa', $this->editor);

    expect(ParameterRecord::query()->where('clave', '14')->count())->toBe(2)
        ->and($this->parameters->book()->forPeriod($this->august)->code(14)?->saleRate->percent())->toBe('0.9');
});

it('adds new options and keys that had no version', function () {
    $this->versions->add('opciones', 'opciones', '2026-08-01', [
        'base_venta' => 'reportado',
        'iva_venta' => '19',
        'notas_credito' => 'netear',
        'cruces_en_derivadas' => false,
        'cruces_en_directores' => false,
        'ubicacion_director' => 'aparte',
        'vendedor_cruce' => 'fecha_cruce',
        'alcance_cumplimiento' => 'persona',
        'moneda' => 'reportado',
    ], 'Cumplimiento por persona (punto 14.6)', $this->editor);

    $this->versions->add('codigo', '99', '2026-08-01', [...($this->code14)('0.5'), 'nombre' => 'Código 99', 'persona' => null], 'Código nuevo', $this->editor);

    $august = $this->parameters->book()->forPeriod($this->august);

    expect($august->options()->complianceScope)->toBe(ComplianceScope::Person)
        ->and($august->code(99)?->name)->toBe('Código 99')
        ->and($august->code(99)?->person)->toBeNull()
        ->and($this->parameters->book()->forPeriod($this->july)->code(99))->toBeNull();
});

it('refuses a change that starts in or before the last closed period', function () {
    $result = (new SettlementCalculator)->calculate(new SettlementInput($this->july), DefaultParameters::book()->forPeriod($this->july));
    $draft = Settlement::calculate($result, 'ana@segurex.com', new DateTimeImmutable('2026-08-03 09:00'));
    $approved = $draft->approve('beto@segurex.com', new DateTimeImmutable('2026-08-04 09:00'), 1);
    $store = app(Settlements::class);
    $store->save($draft);
    $store->save($approved);
    $store->save($approved->close('beto@segurex.com', new DateTimeImmutable('2026-08-04 10:00')));

    expect(fn () => $this->versions->add('codigo', '14', '2026-07-31', ($this->code14)(), 'Tarde', $this->editor))
        ->toThrow(ParameterChangeRefused::class, 'El período 2026-07 ya está cerrado: la versión debe empezar el 01/08/2026 o después.');

    expect($this->versions->add('codigo', '14', '2026-08-01', ($this->code14)(), 'A tiempo', $this->editor)->id)->toBeInt();
});

it('refuses a missing motive, a later version and invalid values without writing anything', function () {
    expect(fn () => $this->versions->add('codigo', '14', '2026-08-01', ($this->code14)(), '  ', $this->editor))
        ->toThrow(ParameterChangeRefused::class, 'Escribe el motivo del cambio.');

    $this->versions->add('codigo', '14', '2026-09-01', ($this->code14)('1'), 'Desde septiembre', $this->editor);

    expect(fn () => $this->versions->add('codigo', '14', '2026-08-01', ($this->code14)('0.9'), 'Desde agosto', $this->editor))
        ->toThrow(ParameterChangeRefused::class, 'Ya hay una versión de este parámetro que empieza el 01/09/2026: agrega el cambio desde esa fecha o después.');

    // A tranche table with a gap between 65 and 70 days.
    expect(fn () => $this->versions->add('tramos', 'tramos', '2026-10-01', ['tramos' => [
        ['desde' => 0, 'hasta' => 65, 'participacion' => '100'],
        ['desde' => 70, 'hasta' => null, 'participacion' => '0'],
    ]], 'Tramos nuevos', $this->editor))->toThrow(function (ParameterChangeRefused $refused) {
        expect($refused->getMessage())->toBe('Los valores de la versión no son válidos. Revisa los porcentajes, los días y los códigos.')
            ->and($refused->detail())->toContain('gap');
    });

    expect(fn () => $this->versions->add('codigo', '14', '2026-10-01', [...($this->code14)(), 'tasa_venta' => '-1'], 'Negativa', $this->editor))
        ->toThrow(ParameterChangeRefused::class)
        ->and(fn () => $this->versions->add('inventado', 'x', '2026-10-01', [], 'Tipo raro', $this->editor))
        ->toThrow(ParameterChangeRefused::class, 'El tipo de parámetro inventado no existe.');

    expect(ParameterRecord::query()->whereNotNull('created_by')->count())->toBe(1)
        ->and(ParameterRecord::query()->where('tipo', 'tramos')->sole()->vigente_hasta)->toBeNull();
});
