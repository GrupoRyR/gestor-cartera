<?php

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
 * ingesta:limpiar-cargas-manuales runs on 15/10/2026 at 03:00 in Bogotá and
 * deletes the manual uploads (local disk, ingesta/manual/) nobody
 * confirmed or discarded within ingesta.manual_upload_ttl_days.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 03:00:00', 'America/Bogota'));
    Storage::fake('local');
});

/**
 * Writes a file on the fake local disk last modified $hours hours ago.
 */
function ingestaStoredFile(string $path, int $hours): void
{
    $disk = Storage::disk('local');
    $disk->put($path, 'xlsx');
    touch($disk->path($path), CarbonImmutable::now()->subHours($hours)->getTimestamp());
}

it('deletes the manual uploads older than the TTL and keeps the recent ones', function () {
    ingestaStoredFile('ingesta/manual/old.xlsx', 8 * 24);
    ingestaStoredFile('ingesta/manual/older.xlsx', 30 * 24);
    ingestaStoredFile('ingesta/manual/recent.xlsx', 6 * 24);
    ingestaStoredFile('ingesta/manual/today.xlsx', 1);

    $this->artisan('ingesta:limpiar-cargas-manuales')
        ->expectsOutput('Se borraron 2 cargas manuales sin confirmar.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing([
        'ingesta/manual/recent.xlsx',
        'ingesta/manual/today.xlsx',
    ]);
});

it('leaves the files outside ingesta/manual untouched', function () {
    ingestaStoredFile('ingesta/otro.xlsx', 30 * 24);
    ingestaStoredFile('ingesta/manual/sub/old.xlsx', 30 * 24);
    ingestaStoredFile('reportes/old.xlsx', 30 * 24);

    $this->artisan('ingesta:limpiar-cargas-manuales')
        ->expectsOutput('No había cargas manuales sin confirmar para borrar.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toEqualCanonicalizing([
        'ingesta/otro.xlsx',
        'ingesta/manual/sub/old.xlsx',
        'reportes/old.xlsx',
    ]);
});

it('does nothing when the directory is empty or missing', function (bool $create) {
    if ($create) {
        Storage::disk('local')->makeDirectory('ingesta/manual');
    }

    $this->artisan('ingesta:limpiar-cargas-manuales')
        ->expectsOutput('No había cargas manuales sin confirmar para borrar.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'empty' => [true],
    'missing' => [false],
]);

it('honors the configured TTL', function () {
    config(['ingesta.manual_upload_ttl_days' => 2]);
    ingestaStoredFile('ingesta/manual/three-days.xlsx', 3 * 24);
    ingestaStoredFile('ingesta/manual/one-day.xlsx', 24);

    $this->artisan('ingesta:limpiar-cargas-manuales')
        ->expectsOutput('Se borró 1 carga manual sin confirmar.')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe(['ingesta/manual/one-day.xlsx']);
});

it('keeps uploads for 7 days by default', function () {
    expect(config('ingesta.manual_upload_ttl_days'))->toBe(7);
});

it('runs every day at 03:00 in Bogotá without overlapping', function () {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'ingesta:limpiar-cargas-manuales'),
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->expression)->toBe('0 3 * * *')
        ->and($events[0]->timezone)->toBe('America/Bogota')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->expiresAt)->toBe(10);
});
