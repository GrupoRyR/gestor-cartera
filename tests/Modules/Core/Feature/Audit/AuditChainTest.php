<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Infrastructure\Audit\AuditChain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    // The verifier writes its checkpoint here, never in the real storage folder.
    Storage::fake('local');
});

/**
 * The stored row of audit_logs with this id, as the query builder reads it.
 *
 * @return array<string, mixed>
 */
function auditRow(int $id): array
{
    return (array) DB::table('audit_logs')->where('id', $id)->first();
}

/**
 * @return list<array<string, mixed>>
 */
function auditRows(): array
{
    return DB::table('audit_logs')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
}

function auditHead(): object
{
    $head = DB::table('audit_chain_head')->where('id', 1)->first();

    expect($head)->not->toBeNull();

    return $head;
}

it('chains consecutive records: each hash depends on the one before', function () {
    $audit = app(AuditLogger::class);
    $chain = app(AuditChain::class);

    $audit->record('core.user.created', 'user', 1, context: ['source' => 'test']);
    $audit->record('core.user.updated', 'user', 1, ['name' => ['old' => 'Ana', 'new' => 'Ana María']]);

    [$first, $second] = auditRows();

    expect($first['chain_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($second['chain_hash'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($chain->hash(null, $first))->toBe($first['chain_hash'])
        ->and($chain->hash($first['chain_hash'], $second))->toBe($second['chain_hash'])
        // The same row after another predecessor gets another hash.
        ->and($chain->hash(null, $second))->not->toBe($second['chain_hash'])
        ->and($chain->matches($first['chain_hash'], $second, $second['chain_hash']))->toBeTrue()
        ->and($chain->matches(null, $second, $second['chain_hash']))->toBeFalse();
});

it('moves the head to the last recorded row', function () {
    $audit = app(AuditLogger::class);

    $audit->record('core.user.created', 'user', 1);
    $audit->record('core.user.created', 'user', 2);

    $last = auditRows()[1];
    $head = auditHead();

    expect((int) $head->last_id)->toBe((int) $last['id'])
        ->and($head->last_hash)->toBe($last['chain_hash']);
});

it('leaves no row and the head unchanged when the caller rolls back', function () {
    app(AuditLogger::class)->record('core.user.created', 'user', 1);
    $before = auditHead();

    expect(fn () => DB::transaction(function () {
        app(AuditLogger::class)->record('core.user.created', 'user', 2);

        throw new RuntimeException('The caller failed.');
    }))->toThrow(RuntimeException::class);

    $after = auditHead();

    expect(DB::table('audit_logs')->count())->toBe(1)
        ->and($after->last_id)->toEqual($before->last_id)
        ->and($after->last_hash)->toBe($before->last_hash);
});

it('hashes the decoded values, not the stored JSON text', function () {
    app(AuditLogger::class)->record(
        'cobro.exclusion.updated',
        'cobro_exclusion',
        7,
        ['reason' => ['old' => null, 'new' => 'Acuerdo'], 'amount' => ['old' => 1500.5, 'new' => 0]],
        ['company' => 'SGX', 'batch' => 12, 'nested' => ['b' => 2, 'a' => 1]],
    );

    $row = auditRow((int) DB::table('audit_logs')->value('id'));
    $chain = app(AuditChain::class);

    // What MySQL's JSON type may give back: other key order, other spacing.
    $reordered = [
        ...$row,
        'changes' => '{"amount": {"new": 0, "old": 1500.5}, "reason": {"new": "Acuerdo", "old": null}}',
        'context' => '{"batch": 12, "nested": {"a": 1, "b": 2}, "company": "SGX"}',
    ];

    expect($chain->hash(null, $reordered))->toBe($row['chain_hash'])
        ->and($chain->hash(null, [...$row, 'context' => '{"batch": 13, "nested": {"a": 1, "b": 2}, "company": "SGX"}']))
        ->not->toBe($row['chain_hash']);
});

it('still matches a row hashed with a key that moved to APP_PREVIOUS_KEYS', function () {
    app(AuditLogger::class)->record('core.user.created', 'user', 1);
    $row = auditRow((int) DB::table('audit_logs')->value('id'));
    $oldKey = config('app.key');

    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$oldKey]]);
    $chain = app(AuditChain::class);

    expect($chain->matches(null, $row, $row['chain_hash']))->toBeTrue()
        // New rows are hashed with the current key only.
        ->and($chain->hash(null, $row))->not->toBe($row['chain_hash']);

    config(['app.previous_keys' => []]);

    expect($chain->matches(null, $row, $row['chain_hash']))->toBeFalse();
});

it('seals the rows that existed before the chain, in id order', function () {
    foreach (['core.user.created', 'core.user.updated', 'core.user.deactivated'] as $i => $action) {
        DB::table('audit_logs')->insert([
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => '1',
            'context' => json_encode(['step' => $i]),
            'created_at' => '2026-10-0'.($i + 1).' 08:00:00',
        ]);
    }
    DB::table('audit_chain_head')->where('id', 1)->update(['last_id' => null, 'last_hash' => null]);

    expect(app(AuditChain::class)->seal())->toBe(3);

    $rows = auditRows();
    $chain = app(AuditChain::class);

    expect($chain->hash(null, $rows[0]))->toBe($rows[0]['chain_hash'])
        ->and($chain->hash($rows[0]['chain_hash'], $rows[1]))->toBe($rows[1]['chain_hash'])
        ->and($chain->hash($rows[1]['chain_hash'], $rows[2]))->toBe($rows[2]['chain_hash'])
        ->and((int) auditHead()->last_id)->toBe((int) $rows[2]['id'])
        ->and(auditHead()->last_hash)->toBe($rows[2]['chain_hash']);

    $this->artisan('core:verificar-auditoria')->assertSuccessful();
});
