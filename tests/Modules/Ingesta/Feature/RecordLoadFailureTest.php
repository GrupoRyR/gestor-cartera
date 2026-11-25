<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Ingesta\Application\Failures\LoadFailure;
use App\Modules\Ingesta\Application\Failures\RecordLoadFailure;
use App\Modules\Ingesta\Application\Failures\SendLoadFailureAlert;
use App\Modules\Ingesta\Domain\Failures\FailureCause;
use App\Modules\Ingesta\Domain\Failures\FailureSource;
use App\Modules\Ingesta\Domain\Failures\FailureStage;
use App\Modules\Ingesta\Infrastructure\Persistence\LoadFailureRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 05:30:00', 'America/Bogota'));
});

function loadFailure(string $message = 'Sin ruta a HANA', string $reportId = '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b'): LoadFailure
{
    return new LoadFailure(
        source: FailureSource::BasesSap,
        reportId: $reportId,
        runId: '0a9b8c7d-6e5f-4a3b-8c2d-1e0f9a8b7c6d',
        company: CompanyCode::SDC,
        dataset: Dataset::Sales,
        slotAt: CarbonImmutable::parse('2026-11-23T10:00:00+00:00'),
        stage: FailureStage::Vpn,
        cause: FailureCause::Vpn,
        transient: false,
        attempt: 1,
        maxAttempts: 3,
        final: false,
        message: $message,
        occurredAt: CarbonImmutable::parse('2026-11-23T10:20:00+00:00'),
    );
}

it('records a new failure once and reports a repeated report id as a duplicate', function () {
    $record = app(RecordLoadFailure::class);

    expect($record->record(loadFailure()))->toBeTrue()
        ->and($record->record(loadFailure('Otro mensaje')))->toBeFalse()
        ->and($record->record(loadFailure(reportId: '11111111-2222-4333-8444-555555555555')))->toBeTrue()
        ->and(LoadFailureRecord::query()->count())->toBe(2)
        ->and(LoadFailureRecord::query()->where('report_id', '6f1c2b0e-4d3a-4f5e-9a7b-1c2d3e4f5a6b')->sole()->message)->toBe('Sin ruta a HANA');
});

it('stores the failure as given, with its times in the app timezone', function () {
    app(RecordLoadFailure::class)->record(loadFailure());

    $failure = LoadFailureRecord::query()->sole();

    expect($failure->source)->toBe(FailureSource::BasesSap)
        ->and($failure->company)->toBe(CompanyCode::SDC)
        ->and($failure->dataset)->toBe(Dataset::Sales)
        ->and($failure->stage)->toBe(FailureStage::Vpn)
        ->and($failure->cause)->toBe(FailureCause::Vpn)
        ->and($failure->transient)->toBeFalse()
        ->and($failure->final)->toBeFalse()
        ->and($failure->attempt)->toBe(1)
        ->and($failure->max_attempts)->toBe(3)
        ->and($failure->slot_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:00:00 America/Bogota')
        ->and($failure->occurred_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:20:00 America/Bogota')
        ->and($failure->received_at->format('Y-m-d H:i:s e'))->toBe('2026-11-23 05:30:00 America/Bogota');
});

it('cleans the message before storing it', function (string $message, string $stored) {
    app(RecordLoadFailure::class)->record(loadFailure($message));

    expect(LoadFailureRecord::query()->sole()->message)->toBe($stored);
})->with([
    'control characters' => ["Fallo\x00 en\x1b[31m HANA\x7F", 'Fallo en[31m HANA'],
    'line breaks and tabs' => ["Línea 1\r\nLínea 2\tfin", 'Línea 1 Línea 2 fin'],
    'repeated and outer whitespace' => ["  mucho \u{00A0}  espacio  ", 'mucho espacio'],
    'invisible format characters' => ["texto\u{202E}invertido\u{200B}", 'textoinvertido'],
    'invalid UTF-8' => ["conexi\xF3n perdida", 'conexi?n perdida'],
]);

it('cuts the message to 300 characters without breaking a multibyte one', function () {
    app(RecordLoadFailure::class)->record(loadFailure(str_repeat('é', 299).'€ y más'));

    $stored = LoadFailureRecord::query()->sole()->message;

    expect(mb_strlen($stored))->toBe(300)
        ->and(mb_check_encoding($stored, 'UTF-8'))->toBeTrue()
        ->and($stored)->toEndWith('é€');
});

it('trims the whitespace left at the end of the cut', function () {
    app(RecordLoadFailure::class)->record(loadFailure(str_repeat('a', 299).' b'));

    expect(LoadFailureRecord::query()->sole()->message)->toBe(str_repeat('a', 299));
});

it('queues the alert for a new final report only', function () {
    Queue::fake();
    $record = app(RecordLoadFailure::class);

    $record->record(loadFailure());
    Queue::assertNothingPushed();

    $final = new LoadFailure(...[...get_object_vars(loadFailure(reportId: '11111111-2222-4333-8444-555555555555')), 'final' => true]);
    $record->record($final);
    $record->record($final);

    $id = LoadFailureRecord::query()->where('report_id', '11111111-2222-4333-8444-555555555555')->sole()->id;
    Queue::assertPushed(SendLoadFailureAlert::class, 1);
    Queue::assertPushed(SendLoadFailureAlert::class, fn (SendLoadFailureAlert $job): bool => $job->failureId === $id);
});
