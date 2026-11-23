<?php

use App\Models\User;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Infrastructure\Notices\EloquentAvisoService;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
    $this->ana = User::factory()->create();
    $this->beto = User::factory()->create();
    $this->avisos = app(EloquentAvisoService::class);
});

it('is the AvisoService of the container, one instance for the whole app', function () {
    expect(app(AvisoService::class))->toBeInstanceOf(EloquentAvisoService::class)
        ->and(app(AvisoService::class))->toBe(app(AvisoService::class))
        ->and(app(AvisoService::class))->toBe(app(EloquentAvisoService::class));
});

it('creates the notices table with its indexes', function () {
    $indexes = array_map(fn (array $index): array => $index['columns'], Schema::getIndexes('notices'));

    expect(Schema::hasColumns('notices', [
        'id', 'user_id', 'company', 'kind', 'title', 'body', 'subject_type', 'subject_id',
        'key', 'data', 'read_at', 'resolved_at', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and($indexes)->toContain(['user_id', 'read_at'], ['key', 'resolved_at']);
});

it('stores one unread, open notice per recipient', function () {
    $this->avisos->send(new Aviso(
        recipients: [$this->ana->id, $this->beto->id, $this->ana->id],
        kind: 'cartera.compromiso',
        title: 'Pago parcial recibido',
        body: 'Ferretería El Tornillo abonó $ 1.000.000.',
        company: 'SGX',
        subjectType: 'commitment',
        subjectId: 12,
        key: 'commitment:12:partial',
        data: ['amount' => 1_000_000],
    ));

    $notices = $this->avisos->latest($this->ana->id);

    expect(NoticeRecord::query()->pluck('user_id')->sort()->values()->all())->toBe([$this->ana->id, $this->beto->id])
        ->and($notices)->toHaveCount(1)
        ->and($notices[0])->toEqual(new Notice(
            id: $notices[0]->id,
            userId: $this->ana->id,
            company: 'SGX',
            kind: 'cartera.compromiso',
            title: 'Pago parcial recibido',
            body: 'Ferretería El Tornillo abonó $ 1.000.000.',
            subjectType: 'commitment',
            subjectId: 12,
            key: 'commitment:12:partial',
            data: ['amount' => 1_000_000],
            readAt: null,
            resolvedAt: null,
            createdAt: CarbonImmutable::parse('2026-11-20 09:00:00'),
        ));
});

it('reads a notice without data as an empty array', function () {
    $this->avisos->send(new Aviso([$this->ana->id], 'core.prueba', 'Hola'));

    expect(NoticeRecord::query()->sole()->getRawOriginal('data'))->toBeNull()
        ->and($this->avisos->latest($this->ana->id)[0]->data)->toBe([]);
});

it('writes inside the caller transaction, so a rollback leaves no notice', function () {
    expect(fn () => DB::transaction(function () {
        $this->avisos->send(new Aviso([$this->ana->id, $this->beto->id], 'core.prueba', 'Hola'));

        throw new RuntimeException('The caller failed.');
    }))->toThrow(RuntimeException::class);

    expect(NoticeRecord::query()->count())->toBe(0);
});

it('resolves only the open notices with that key', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->create(['key' => 'commitment:1:partial']);
    NoticeRecord::factory()->for($this->beto, 'user')->read()->create(['key' => 'commitment:1:partial']);
    $done = NoticeRecord::factory()->for($this->ana, 'user')->create([
        'key' => 'commitment:1:partial',
        'resolved_at' => '2026-11-19 08:00:00',
    ]);
    $other = NoticeRecord::factory()->for($this->ana, 'user')->create(['key' => 'commitment:2:partial']);
    $this->travel(1)->hour();

    expect($this->avisos->resolve('commitment:1:partial'))->toBe(2)
        ->and($this->avisos->resolve('commitment:1:partial'))->toBe(0)
        ->and(NoticeRecord::query()->where('key', 'commitment:1:partial')->whereNull('resolved_at')->count())->toBe(0)
        ->and($done->refresh()->resolved_at?->toDateTimeString())->toBe('2026-11-19 08:00:00')
        ->and($other->refresh()->resolved_at)->toBeNull()
        ->and(NoticeRecord::query()->where('key', 'commitment:1:partial')->where('resolved_at', '2026-11-20 10:00:00')->count())->toBe(2);
});

it('resolving leaves the read state alone', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->create(['key' => 'k']);
    $this->avisos->resolve('k');

    expect($this->avisos->latest($this->ana->id)[0]->isRead())->toBeFalse()
        ->and($this->avisos->latest($this->ana->id)[0]->isResolved())->toBeTrue();
});

it('lists the inbox of a user, newest first', function () {
    $old = NoticeRecord::factory()->for($this->ana, 'user')->read()->create(['created_at' => '2026-11-18 08:00:00']);
    $new = NoticeRecord::factory()->for($this->ana, 'user')->create(['created_at' => '2026-11-19 08:00:00']);
    $tie = NoticeRecord::factory()->for($this->ana, 'user')->create(['created_at' => '2026-11-19 08:00:00']);
    NoticeRecord::factory()->for($this->beto, 'user')->create();

    $page = $this->avisos->forUser($this->ana->id, perPage: 2);
    $unread = $this->avisos->forUser($this->ana->id, unreadOnly: true);

    expect(array_map(fn (Notice $notice): int => $notice->id, $page->items()))->toBe([$tie->id, $new->id])
        ->and($page->total())->toBe(3)
        ->and(array_map(fn (Notice $notice): int => $notice->id, $unread->items()))->toBe([$tie->id, $new->id])
        ->and(array_map(fn (Notice $notice): int => $notice->id, $this->avisos->latest($this->ana->id, 2)))->toBe([$tie->id, $new->id])
        ->and(array_map(fn (Notice $notice): int => $notice->id, $this->avisos->latest($this->ana->id)))->toBe([$tie->id, $new->id, $old->id]);
});

it('rejects a page or a limit below one', function () {
    expect(fn () => $this->avisos->forUser($this->ana->id, perPage: 0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->avisos->latest($this->ana->id, 0))->toThrow(InvalidArgumentException::class);
});

it('counts the unread notices that are still open', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->count(2)->create();
    NoticeRecord::factory()->for($this->ana, 'user')->read()->create();
    NoticeRecord::factory()->for($this->ana, 'user')->resolved()->create();
    NoticeRecord::factory()->for($this->beto, 'user')->create();

    expect($this->avisos->unreadCount($this->ana->id))->toBe(2)
        ->and($this->avisos->unreadCount($this->beto->id))->toBe(1);
});

it('marks a notice of the user as read, once', function () {
    $notice = NoticeRecord::factory()->for($this->ana, 'user')->create();

    $read = $this->avisos->markRead($this->ana->id, $notice->id);
    $this->travel(1)->hour();
    $again = $this->avisos->markRead($this->ana->id, $notice->id);

    expect($read?->readAt?->toDateTimeString())->toBe('2026-11-20 09:00:00')
        ->and($again?->readAt?->toDateTimeString())->toBe('2026-11-20 09:00:00')
        ->and($this->avisos->unreadCount($this->ana->id))->toBe(0);
});

it('never marks the notice of another user', function () {
    $notice = NoticeRecord::factory()->for($this->beto, 'user')->create();

    expect($this->avisos->markRead($this->ana->id, $notice->id))->toBeNull()
        ->and($this->avisos->markRead($this->ana->id, 999_999))->toBeNull()
        ->and($notice->refresh()->read_at)->toBeNull();
});

it('marks every unread notice of the user as read', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->count(2)->create();
    $earlier = NoticeRecord::factory()->for($this->ana, 'user')->create(['read_at' => '2026-11-19 08:00:00']);
    $other = NoticeRecord::factory()->for($this->beto, 'user')->create();

    expect($this->avisos->markAllRead($this->ana->id))->toBe(2)
        ->and($this->avisos->unreadCount($this->ana->id))->toBe(0)
        ->and($earlier->refresh()->read_at?->toDateTimeString())->toBe('2026-11-19 08:00:00')
        ->and($other->refresh()->read_at)->toBeNull();
});

it('links a notice with the first resolver of its kind that answers', function () {
    $calls = [];
    $this->avisos->linkUsing('cartera.compromiso', function (Notice $notice) use (&$calls): ?string {
        $calls[] = 'first';

        return null;
    });
    $this->avisos->linkUsing('cartera.compromiso', function (Notice $notice) use (&$calls): ?string {
        $calls[] = 'second';

        return "/compromisos/{$notice->subjectId}";
    });
    $this->avisos->linkUsing('cartera.compromiso', function (Notice $notice) use (&$calls): ?string {
        $calls[] = 'third';

        return '/otra';
    });
    $this->avisos->send(new Aviso([$this->ana->id], 'cartera.compromiso', 'Pago parcial', subjectType: 'commitment', subjectId: 12));
    $this->avisos->send(new Aviso([$this->ana->id], 'core.sin_enlace', 'Sin enlace'));

    [$unlinked, $linked] = $this->avisos->latest($this->ana->id);

    expect($this->avisos->link($linked))->toBe('/compromisos/12')
        ->and($calls)->toBe(['first', 'second'])
        ->and($this->avisos->link($unlinked))->toBeNull();
});

it('keeps the resolvers registered through the contract', function () {
    app(AvisoService::class)->linkUsing('core.prueba', fn (Notice $notice): string => '/inicio');
    $this->avisos->send(new Aviso([$this->ana->id], 'core.prueba', 'Hola'));

    expect(app(EloquentAvisoService::class)->link($this->avisos->latest($this->ana->id)[0]))->toBe('/inicio');
});

it('deletes the notices of a user removed for good', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->create();

    $this->ana->forceDelete();

    expect(NoticeRecord::query()->count())->toBe(0);
});
