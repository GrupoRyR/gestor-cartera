<?php

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Infrastructure\Audit\DatabaseAuditLogger;
use App\Modules\Core\Infrastructure\Persistence\AuditLogRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Records one entry from inside a real HTTP request.
    Route::middleware(['web', 'auth'])->get('/_test/auditoria', function (AuditLogger $audit) {
        $audit->record('core.user.deactivated', 'user', 7, ['is_active' => ['old' => true, 'new' => false]]);

        return response()->noContent();
    });
});

it('is the AuditLogger of the container', function () {
    expect(app(AuditLogger::class))->toBeInstanceOf(DatabaseAuditLogger::class);
});

it('records the signed-in user with the IP address and browser of the request', function () {
    $this->freezeSecond();
    $user = User::factory()->create(['email' => 'ana@segurex.com']);

    $this->actingAs($user)
        ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Pest)'])
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->get('/_test/auditoria')
        ->assertNoContent();

    $entry = AuditLogRecord::query()->sole();

    expect($entry->action)->toBe('core.user.deactivated')
        ->and($entry->subject_type)->toBe('user')
        ->and($entry->subject_id)->toBe('7')
        ->and($entry->changes)->toEqual(['is_active' => ['old' => true, 'new' => false]])
        ->and($entry->context)->toBeNull()
        ->and($entry->user_id)->toBe($user->id)
        ->and($entry->actor)->toBe('ana@segurex.com')
        ->and($entry->ip_address)->toBe('203.0.113.9')
        ->and($entry->user_agent)->toBe('Mozilla/5.0 (Pest)')
        ->and($entry->created_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($entry->created_at?->equalTo(now()))->toBeTrue();
});

it('records actions of the system without a user, IP address or browser', function () {
    app(AuditLogger::class)->record('core.client.status_recomputed', 'client', 'C-0042', context: ['batch' => 12]);

    $entry = AuditLogRecord::query()->sole();

    expect($entry->user_id)->toBeNull()
        ->and($entry->actor)->toBeNull()
        ->and($entry->ip_address)->toBeNull()
        ->and($entry->user_agent)->toBeNull()
        ->and($entry->changes)->toBeNull()
        ->and($entry->context)->toBe(['batch' => 12]);
});

it('stores the subject id as a string', function (int|string $id, string $stored) {
    app(AuditLogger::class)->record('cobro.exclusion.created', 'cobro_exclusion', $id);

    expect(AuditLogRecord::query()->sole()->subject_id)->toBe($stored);
})->with([
    'int id' => [42, '42'],
    'string id' => ['SGX-C-0042', 'SGX-C-0042'],
]);

it('keeps the changes and the context as given', function () {
    $changes = [
        'reason' => ['old' => null, 'new' => 'Acuerdo de pago con gerencia'],
        'amount' => ['old' => 1500.5, 'new' => 0],
        'invoices' => ['FV-1', 'FV-2'],
        'notify' => true,
    ];
    $context = ['batch' => 12, 'company' => 'SGX', 'note' => 'Cliente en gestión'];

    app(AuditLogger::class)->record('cobro.exclusion.updated', 'cobro_exclusion', 7, $changes, $context);

    $entry = AuditLogRecord::query()->sole();

    // toEqual, not toBe: MySQL's JSON type may reorder object keys.
    expect($entry->changes)->toEqual($changes)
        ->and($entry->context)->toEqual($context);
});

it('rejects actions that do not follow the naming pattern and records nothing', function (string $action) {
    expect(fn () => app(AuditLogger::class)->record($action))
        ->toThrow(InvalidArgumentException::class, "Audit action [{$action}]");

    expect(AuditLogRecord::query()->count())->toBe(0);
})->with(['cobro', 'cobro.exclusion', 'Cobro.exclusion.created', 'cobro..created', 'cobro.exclusion.created.', '']);

it('redacts passwords, secrets and tokens at any depth', function () {
    app(AuditLogger::class)->record(
        'core.integration.updated',
        'integration',
        'graph',
        changes: [
            'Password' => ['old' => 'hunter2', 'new' => 'hunter3'],
            'name' => ['old' => 'Graph', 'new' => 'Microsoft Graph'],
        ],
        context: [
            'credentials' => ['client_secret' => 's3cr3t', 'API_TOKEN' => 't0k3n', 'tenant' => 'ryr'],
            'calls' => [['api_token' => 'abc123', 'status' => 200]],
        ],
    );

    $entry = AuditLogRecord::query()->sole();

    expect($entry->changes)->toEqual([
        'Password' => '[redacted]',
        'name' => ['old' => 'Graph', 'new' => 'Microsoft Graph'],
    ])->and($entry->context)->toEqual([
        'credentials' => ['client_secret' => '[redacted]', 'API_TOKEN' => '[redacted]', 'tenant' => 'ryr'],
        'calls' => [['api_token' => '[redacted]', 'status' => 200]],
    ]);

    $stored = DB::table('audit_logs')->value('changes').DB::table('audit_logs')->value('context');

    expect($stored)->not->toContain('hunter')
        ->not->toContain('s3cr3t')
        ->not->toContain('t0k3n')
        ->not->toContain('abc123');
});

it('keeps the first 255 characters of a long browser name', function () {
    $userAgent = str_repeat('Navegador ñandú ', 30);

    $this->actingAs(User::factory()->create())
        ->withHeaders(['User-Agent' => $userAgent])
        ->get('/_test/auditoria')
        ->assertNoContent();

    expect(AuditLogRecord::query()->sole()->user_agent)
        ->toBe(mb_substr($userAgent, 0, 255))
        ->toHaveLength(255);
});

it('replaces invalid UTF-8 in the browser name', function () {
    $this->actingAs(User::factory()->create())
        ->withHeaders(['User-Agent' => "Pest \xFF"])
        ->get('/_test/auditoria')
        ->assertNoContent();

    expect(AuditLogRecord::query()->sole()->user_agent)->toBe('Pest ?');
});
