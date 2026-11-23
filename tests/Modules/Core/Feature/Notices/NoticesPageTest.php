<?php

use App\Models\User;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Design\Support\Html;

/**
 * The titles of the notices listed on the /avisos page, in order.
 *
 * @return list<string>
 */
function noticesPageTitles(TestResponse $response): array
{
    $html = Html::parse($response->getContent() ?: '');

    return array_map(fn (DOMElement $title): string => Html::clean($title->textContent), $html->all('//main//li/a[@data-notice]//*[@data-part="title"]'));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
    $this->ana = User::factory()->create();
    $this->beto = User::factory()->create();
});

it('sends guests to the sign-in page', function (string $method, string $uri) {
    $this->call($method, $uri)->assertRedirect(route('core.login'));
})->with([
    'list' => ['GET', '/avisos'],
    'open' => ['GET', '/avisos/1'],
    'mark all as read' => ['POST', '/avisos/leer-todos'],
]);

it('lists the notices of the user, newest first, with the tab "Todos" by default', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->read()->create(['title' => 'Leído', 'company' => 'SDC', 'created_at' => now()->subHour()]);
    NoticeRecord::factory()->for($this->ana, 'user')->create(['title' => 'Por leer']);
    NoticeRecord::factory()->for($this->beto, 'user')->create(['title' => 'De Beto']);

    $response = $this->actingAs($this->ana)->get(route('core.avisos.index'))->assertOk();
    $html = Html::parse($response->getContent() ?: '');

    expect(noticesPageTitles($response))->toBe(['Por leer', 'Leído'])
        ->and($html->text('//h1'))->toBe('Avisos')
        ->and(array_map(fn (DOMElement $a): string => Html::clean($a->textContent), $html->all('//nav[@aria-label="Filtrar avisos"]//a')))->toBe(['Todos', 'No leídos'])
        ->and($html->text('//nav[@aria-label="Filtrar avisos"]//a[@aria-current="page"]'))->toBe('Todos')
        ->and($html->text('//main//li[2]//*[@data-tone="neutral"]'))->toBe('SDC')
        ->and($html->one('//main//form[.//button[normalize-space(.)="Marcar todo como leído"]]')->getAttribute('action'))->toBe(route('core.avisos.read-all'));
});

it('lists only the unread notices on the tab "No leídos"', function () {
    NoticeRecord::factory()->for($this->ana, 'user')->read()->create(['title' => 'Leído']);
    NoticeRecord::factory()->for($this->ana, 'user')->resolved()->create(['title' => 'Resuelto sin leer']);

    $response = $this->actingAs($this->ana)->get(route('core.avisos.index', ['filtro' => 'no-leidos']))->assertOk();
    $html = Html::parse($response->getContent() ?: '');

    expect(noticesPageTitles($response))->toBe(['Resuelto sin leer'])
        ->and($html->text('//nav[@aria-label="Filtrar avisos"]//a[@aria-current="page"]'))->toBe('No leídos')
        ->and($html->text('//main//li[1]//*[@data-tone="success"]'))->toBe('Hecho')
        // Nothing unread and open: no button.
        ->and($html->count('//main//form[contains(@action, "leer-todos")]'))->toBe(0);
});

it('pages the notices 20 at a time and keeps the filter in the links', function () {
    foreach (range(1, 25) as $minutes) {
        NoticeRecord::factory()->for($this->ana, 'user')->create(['title' => "Aviso {$minutes}", 'created_at' => now()->subMinutes($minutes)]);
    }

    $first = $this->actingAs($this->ana)->get(route('core.avisos.index', ['filtro' => 'no-leidos']))->assertOk();
    $second = $this->actingAs($this->ana)->get(route('core.avisos.index', ['filtro' => 'no-leidos', 'page' => 2]))->assertOk();

    expect(noticesPageTitles($first))->toHaveCount(20)
        ->and(noticesPageTitles($second))->toBe(array_map(fn (int $minutes): string => "Aviso {$minutes}", range(21, 25)))
        ->and(Html::parse($first->getContent() ?: '')->one('//a[@rel="next"]')->getAttribute('href'))->toContain('filtro=no-leidos');
});

it('shows an empty state on each tab', function (array $query, string $message) {
    $html = Html::parse($this->actingAs($this->ana)->get(route('core.avisos.index', $query))->assertOk()->getContent() ?: '');

    expect($html->text('//main//*[@data-part="title"]'))->toBe($message);
})->with([
    'all' => [[], 'No tienes avisos'],
    'unread' => [['filtro' => 'no-leidos'], 'No tienes avisos sin leer'],
]);

it('marks a notice as read and follows the link of its kind', function () {
    $notice = NoticeRecord::factory()->for($this->ana, 'user')->create(['kind' => 'cartera.compromiso', 'subject_id' => 12]);
    app(AvisoService::class)->linkUsing('cartera.compromiso', fn (Notice $notice): ?string => null);
    app(AvisoService::class)->linkUsing('cartera.compromiso', fn (Notice $notice): string => "/cartera/clientes/{$notice->subjectId}");

    $this->actingAs($this->ana)->get(route('core.avisos.open', $notice->id))->assertRedirect('/cartera/clientes/12');

    expect($notice->refresh()->read_at)->not->toBeNull();
});

it('goes back to the list when no resolver links the notice', function () {
    $notice = NoticeRecord::factory()->for($this->ana, 'user')->create();

    $this->actingAs($this->ana)->get(route('core.avisos.open', $notice->id))->assertRedirect(route('core.avisos.index'));

    expect($notice->refresh()->read_at)->not->toBeNull();
});

it('does not open, nor mark, the notice of another user', function () {
    $notice = NoticeRecord::factory()->for($this->beto, 'user')->create();

    $this->actingAs($this->ana)->get(route('core.avisos.open', $notice->id))->assertNotFound();

    expect($notice->refresh()->read_at)->toBeNull();
});

it('marks every notice of the user as read, and only theirs', function () {
    $own = NoticeRecord::factory()->for($this->ana, 'user')->count(2)->create();
    $other = NoticeRecord::factory()->for($this->beto, 'user')->create();

    $this->actingAs($this->ana)
        ->from(route('core.avisos.index'))
        ->post(route('core.avisos.read-all'))
        ->assertRedirect(route('core.avisos.index'))
        ->assertSessionHas('status', 'Marcaste todos los avisos como leídos.');

    expect($own->map(fn (NoticeRecord $notice) => $notice->refresh()->read_at)->filter())->toHaveCount(2)
        ->and($other->refresh()->read_at)->toBeNull();
});
