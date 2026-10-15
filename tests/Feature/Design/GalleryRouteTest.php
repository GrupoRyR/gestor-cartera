<?php

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
