<?php

use App\View\Components\Support\IconSet;
use Tests\Feature\Design\Support\Html;

it('draws every icon the components and layouts ask for', function (string $name) {
    expect(IconSet::has($name))->toBeTrue();
})->with([
    // docs/arquitectura.md §8.2
    'close', 'sort', 'sort-asc', 'sort-desc', 'trend-up', 'trend-down', 'trend-flat',
    // alerts, select, pagination, external links and the layouts
    'info', 'success', 'warning', 'danger', 'chevron-down', 'chevron-left', 'chevron-right',
    'external', 'menu', 'wallet', 'logout',
]);

it('draws an inline SVG that assistive technology skips', function () {
    $html = Html::parse((string) $this->blade('<x-ui.icon name="calendar" />'));

    $icon = $html->one('//*[@data-icon="calendar"]');
    $svg = $html->one('//*[@data-icon="calendar"]/*[local-name()="svg"]');

    expect($icon->getAttribute('aria-hidden'))->toBe('true')
        ->and($svg->getAttribute('aria-hidden'))->toBe('true')
        ->and($svg->getAttribute('focusable'))->toBe('false')
        ->and($svg->getAttribute('stroke'))->toBe('currentColor')
        ->and($svg->hasAttribute('role'))->toBeFalse();
});

it('keeps the data-icon hook but draws nothing for an unknown name', function () {
    $html = Html::parse((string) $this->blade('<x-ui.icon name="no-existe" />'));

    expect($html->count('//*[@data-icon="no-existe"]'))->toBe(1)
        ->and($html->count('//*[local-name()="svg"]'))->toBe(0);
});

it('lets a size class replace the default size', function () {
    $default = Html::parse((string) $this->blade('<x-ui.icon name="search" />'))->one('//*[@data-icon]');
    $custom = Html::parse((string) $this->blade('<x-ui.icon name="search" class="size-8" />'))->one('//*[@data-icon]');

    expect(explode(' ', $default->getAttribute('class')))->toContain('size-5')
        ->and(explode(' ', $custom->getAttribute('class')))->toContain('size-8')->not->toContain('size-5');
});

it('keeps the default size below a responsive-only size', function () {
    $icon = Html::parse((string) $this->blade('<x-ui.icon name="search" class="md:size-6" />'))->one('//*[@data-icon]');

    expect(explode(' ', $icon->getAttribute('class')))->toContain('size-5', 'md:size-6');
});
