<?php

use App\Models\User;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Tests\Fakes\ContractFakes;
use Tests\Feature\Design\Support\Html;

/**
 * The app layout as the signed-in user (if any) sees it, parsed.
 */
function noticeBellLayout(): Html
{
    return Html::parse(Blade::render('<x-layouts.app>Contenido</x-layouts.app>'));
}

const NOTICE_BELL = '//header//details[@data-part="notices"]';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
    ContractFakes::install($this->app);
    $this->ana = User::factory()->create();
    $this->beto = User::factory()->create();
});

it('shows the bell just before the account menu, without a badge when nothing is unread', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->read()->create();
    $this->actingAs($this->ana);

    $html = noticeBellLayout();
    $summary = $html->one(NOTICE_BELL.'/summary');

    expect($summary->getAttribute('aria-label'))->toBe('Avisos')
        ->and($html->count(NOTICE_BELL.'/summary//*[@data-icon="bell"]'))->toBe(1)
        ->and($html->count(NOTICE_BELL.'//*[@data-part="badge"]'))->toBe(0)
        ->and($html->count(NOTICE_BELL.'/following-sibling::details[1][@data-part="account"]'))->toBe(1);
});

it('counts only the unread, open notices of the signed-in user', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->count(2)->create();
    NoticeRecord::factory()->for($this->ana, 'user')->resolved()->create();
    NoticeRecord::factory()->for($this->ana, 'user')->read()->create();
    NoticeRecord::factory()->for($this->beto, 'user')->count(3)->create();
    $this->actingAs($this->ana);

    $html = noticeBellLayout();

    expect($html->one(NOTICE_BELL.'/summary')->getAttribute('aria-label'))->toBe('Avisos, 2 sin leer')
        ->and($html->text(NOTICE_BELL.'/summary//*[@data-part="badge"]'))->toBe('2');
});

it('caps the badge at 9+', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->count(10)->create();
    $this->actingAs($this->ana);

    $html = noticeBellLayout();

    expect($html->text(NOTICE_BELL.'/summary//*[@data-part="badge"]'))->toBe('9+')
        ->and($html->one(NOTICE_BELL.'/summary')->getAttribute('aria-label'))->toBe('Avisos, 10 sin leer');
});

it('lists the 10 newest notices of the user, each a link that opens it', function () {
    foreach (range(1, 12) as $minutes) {
        NoticeRecord::factory()->for($this->ana, 'user')->create([
            'title' => "Aviso {$minutes}",
            'created_at' => now()->subMinutes($minutes),
        ]);
    }
    NoticeRecord::factory()->for($this->beto, 'user')->create(['title' => 'Aviso de Beto']);
    $this->actingAs($this->ana);

    $html = noticeBellLayout();
    $links = $html->all(NOTICE_BELL.'//ul/li/a');
    $titles = $html->all(NOTICE_BELL.'//ul/li/a//*[@data-part="title"]');
    $first = NoticeRecord::query()->where('title', 'Aviso 1')->sole();

    expect(array_map(fn (DOMElement $title): string => Html::clean($title->textContent), $titles))
        ->toBe(array_map(fn (int $minutes): string => "Aviso {$minutes}", range(1, 10)))
        ->and($links)->toHaveCount(10)
        ->and($links[0]->getAttribute('href'))->toBe(route('core.avisos.open', $first->id))
        ->and($html->text(NOTICE_BELL.'//ul/li[1]//time'))->toBe('hace 1 minuto')
        ->and(Html::clean($html->one(NOTICE_BELL)->textContent))->not->toContain('Aviso de Beto');
});

it('marks unread notices and mutes resolved ones as done', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->create(['title' => 'Por leer', 'body' => 'Revisa el compromiso.']);
    NoticeRecord::factory()->for($this->ana, 'user')->read()->create(['title' => 'Leído', 'created_at' => now()->subMinute()]);
    NoticeRecord::factory()->for($this->ana, 'user')->resolved()->create(['title' => 'Resuelto', 'created_at' => now()->subMinutes(2)]);
    $this->actingAs($this->ana);

    $html = noticeBellLayout();

    expect($html->one(NOTICE_BELL.'//ul/li[1]/a')->getAttribute('data-state'))->toBe('unread')
        ->and($html->text(NOTICE_BELL.'//ul/li[1]//*[@data-part="body"]'))->toBe('Revisa el compromiso.')
        ->and($html->one(NOTICE_BELL.'//ul/li[2]/a')->getAttribute('data-state'))->toBe('read')
        ->and($html->one(NOTICE_BELL.'//ul/li[3]/a')->getAttribute('data-state'))->toBe('resolved')
        ->and($html->text(NOTICE_BELL.'//ul/li[3]//*[@data-tone="success"]'))->toBe('Hecho')
        ->and($html->count(NOTICE_BELL.'//ul/li[position() < 3]//*[@data-tone="success"]'))->toBe(0);
});

it('offers to mark everything as read only while something is unread, and links to the full list', function () {
    $this->actingAs($this->ana);
    $empty = noticeBellLayout();

    NoticeRecord::factory()->for($this->ana, 'user')->create();
    $html = noticeBellLayout();
    $form = $html->one(NOTICE_BELL.'//form');

    expect($empty->text(NOTICE_BELL.'//*[@data-part="empty"]'))->toBe('No tienes avisos')
        ->and($empty->count(NOTICE_BELL.'//form'))->toBe(0)
        ->and(strtoupper($form->getAttribute('method')))->toBe('POST')
        ->and($form->getAttribute('action'))->toBe(route('core.avisos.read-all'))
        ->and($html->text(NOTICE_BELL.'//form//button'))->toBe('Marcar todo como leído')
        ->and($html->one(NOTICE_BELL.'//a[normalize-space(.)="Ver todos"]')->getAttribute('href'))->toBe(route('core.avisos.index'));
});

it('shows no bell to guests', function () {
    expect(noticeBellLayout()->count('//details[@data-part="notices"]'))->toBe(0);
});
