<?php

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\View\Components\Support\IconSet;
use Tests\Feature\Design\Support\Html;

it('hides the component gallery outside the local environment', function () {
    $this->get('/dev/componentes')->assertNotFound();
});

it('shows a section for every component in the local environment', function () {
    $this->app['env'] = 'local';

    $html = Html::parse($this->get(route('dev.componentes'))->assertOk()->getContent() ?: '');

    $components = [
        'layouts.app', 'layouts.guest', 'ui.page-header', 'ui.card', 'ui.stat', 'ui.chip',
        'ui.button', 'ui.link-button', 'ui.table', 'ui.empty', 'ui.alert', 'ui.input',
        'ui.select', 'ui.checkbox', 'ui.textarea', 'ui.modal', 'ui.tabs', 'ui.badge-state',
        'ui.money', 'ui.date', 'ui.icon', 'ui.pagination',
    ];

    $ids = array_map(fn (DOMElement $element): string => $element->getAttribute('id'), $html->all('//*[@id]'));

    expect($html->text('//h1'))->toBe('Componentes')
        ->and(array_diff_assoc($ids, array_unique($ids)))->toBe([]);

    foreach ($components as $component) {
        $section = $html->byId('component-'.str_replace('.', '-', $component));
        $heading = $html->byId($section->getAttribute('aria-labelledby'));

        expect($section->nodeName)->toBe('section')
            ->and(Html::clean($heading->textContent))->toBe("<x-{$component}>");
    }
});

it('previews each company brand and switches it with the header selector', function (string $empresa, BrandPalette $palette) {
    $this->app['env'] = 'local';

    $rendered = $this->get(route('dev.componentes', ['empresa' => $empresa]))->assertOk()->getContent() ?: '';
    $html = Html::parse($rendered);
    $form = $html->one('//form[.//select[@name="empresa"]]');

    expect($rendered)->toContain('--brand-button: '.$palette->button.';')
        ->and(strtoupper($form->getAttribute('method')))->toBe('GET')
        ->and($form->getAttribute('action'))->toBe(route('dev.componentes'))
        ->and($html->count('//form[.//select[@name="empresa"]]//input[@name="_token"]'))->toBe(0)
        ->and($html->one('//select[@name="empresa"]/option[@selected]')->getAttribute('value'))->toBe($empresa)
        ->and($html->count('//nav[@aria-label="Navegación principal"]//a'))->toBeGreaterThan(0)
        ->and($html->count('//nav[@aria-label="Accesos rápidos"]//a'))->toBeGreaterThan(0);
})->with([
    'Segurex' => ['SGX', BrandPalette::official(CompanyCode::SGX)],
    'Derivados' => ['SDC', BrandPalette::official(CompanyCode::SDC)],
    'group view' => ['grupo', BrandPalette::neutral()],
]);

it('falls back to Segurex for an unexpected brand parameter', function () {
    $this->app['env'] = 'local';

    $rendered = $this->get('/dev/componentes?empresa[]=SDC')->assertOk()->getContent() ?: '';

    expect($rendered)->toContain('--brand-button: '.BrandPalette::official(CompanyCode::SGX)->button.';');
});

it('previews the chrome without signing anyone in', function () {
    $this->app['env'] = 'local';

    $this->get(route('dev.componentes'))
        ->assertOk()
        ->assertSessionMissing(auth()->guard()->getName());
});

it('shows every icon of the set by name', function () {
    $this->app['env'] = 'local';

    $html = Html::parse($this->get(route('dev.componentes'))->assertOk()->getContent() ?: '');
    $names = array_map(fn (DOMElement $code): string => Html::clean($code->textContent), $html->all('//*[@id="component-ui-icon"]//li/code'));

    expect($names)->toBe(IconSet::names());
});

it('previews the sign-in page with the guest layout', function () {
    $this->app['env'] = 'local';

    $rendered = $this->get(route('dev.componentes', ['vista' => 'ingreso']))->assertOk()->getContent() ?: '';
    $html = Html::parse($rendered);

    expect($html->text('//h1'))->toBe('Ingresa a tu cartera')
        ->and($html->count('//nav'))->toBe(0)
        ->and($html->text('//main//a[@data-variant="primary"]'))->toBe('Entrar con Microsoft')
        ->and($html->count('//footer//img'))->toBe(2)
        ->and($rendered)->toContain('--brand-button: '.BrandPalette::neutral()->button.';');
});
