<?php

use App\Modules\Ingesta\Domain\Watch\LoadSlots;
use Carbon\CarbonImmutable;

/**
 * A slots file with $slots, Bogotá and a 30-minute watch.
 *
 * @param  list<array<string, mixed>>  $slots
 * @return array<string, mixed>
 */
function slotsFile(array $slots): array
{
    return ['zona' => 'America/Bogota', 'vigilante_min' => 30, 'horarios' => $slots];
}

it('parses the slots, their days and what they come before', function () {
    $slots = LoadSlots::fromArray(slotsFile([
        ['hora' => '00:00'],
        ['hora' => '16:00', 'dias' => ['wed'], 'antes_de' => 'el cobro semanal de las 17:00'],
    ]));

    expect($slots->timezone)->toBe('America/Bogota')
        ->and($slots->watchDelay)->toBe(30)
        ->and($slots->slots)->toHaveCount(2)
        ->and($slots->find('00:00')?->watchAt(30))->toBe('00:30')
        ->and($slots->find('00:00')?->days)->toBe([])
        ->and($slots->find('16:00')?->days)->toBe(['wed'])
        ->and($slots->find('16:00')?->dayNumbers())->toBe([3])
        ->and($slots->find('16:00')?->before)->toBe('el cobro semanal de las 17:00')
        ->and($slots->find('16:00')?->watchAt(30))->toBe('16:30')
        ->and($slots->find('12:00'))->toBeNull();
});

it('tells whether a slot runs on a day', function () {
    $slot = LoadSlots::fromArray(slotsFile([['hora' => '16:00', 'dias' => ['wed']]]))->find('16:00');

    expect($slot?->runsOn(CarbonImmutable::parse('2026-11-25')))->toBeTrue()
        ->and($slot?->runsOn(CarbonImmutable::parse('2026-11-26')))->toBeFalse()
        ->and(LoadSlots::defaults()->find('05:00')?->runsOn(CarbonImmutable::parse('2026-11-26')))->toBeTrue();
});

it('reads the versioned etl/horarios.json, which holds the default slots', function () {
    $slots = LoadSlots::fromFile(dirname(__DIR__, 4).'/etl/horarios.json');

    expect($slots)->toEqual(LoadSlots::defaults())
        ->and(array_map(fn ($slot) => $slot->time, $slots->slots))->toBe(['00:00', '05:00', '12:00', '16:00']);
});

it('falls back to the default slots when the file is missing', function () {
    expect(LoadSlots::fromFile(dirname(__DIR__).'/no-existe/horarios.json'))->toEqual(LoadSlots::defaults());
});

it('rejects a file that is not JSON', function () {
    $path = tempnam(sys_get_temp_dir(), 'horarios');
    file_put_contents($path, '{"horarios": [');

    expect(fn () => LoadSlots::fromFile($path))->toThrow(UnexpectedValueException::class, 'not valid JSON');

    unlink($path);
});

it('rejects a time that is not HH:MM', function (mixed $time) {
    LoadSlots::fromArray(slotsFile([['hora' => $time]]));
})->with(['25:00', '5:00', '12:60', 'mediodía', 12])
    ->throws(UnexpectedValueException::class, 'HH:MM');

it('rejects a day that is not a three-letter English day name', function (mixed $day) {
    LoadSlots::fromArray(slotsFile([['hora' => '16:00', 'dias' => [$day]]]));
})->with(['wednesday', 'Wed', 'mié', 3])
    ->throws(UnexpectedValueException::class, 'day');

it('rejects a repeated slot, a watch past midnight and a bad delay or zone', function (array $data, string $message) {
    expect(fn () => LoadSlots::fromArray($data))->toThrow(UnexpectedValueException::class, $message);
})->with([
    'repeated' => [slotsFile([['hora' => '05:00'], ['hora' => '05:00']]), 'twice'],
    'past midnight' => [slotsFile([['hora' => '23:45']]), 'midnight'],
    'no delay' => [['vigilante_min' => 0, 'horarios' => [['hora' => '05:00']]], 'vigilante_min'],
    'bad zone' => [['zona' => 'Bogotá', 'horarios' => [['hora' => '05:00']]], 'zona'],
    'no slots' => [slotsFile([]), 'horarios'],
]);
