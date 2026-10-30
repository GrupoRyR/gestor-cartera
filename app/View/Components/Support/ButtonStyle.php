<?php

namespace App\View\Components\Support;

/**
 * Classes shared by <x-ui.button>, <x-ui.link-button> and the links that
 * look like buttons (pagination, header menus), so every variant looks the
 * same whatever the element. Tailwind scans this file (resources/css/app.css).
 */
final class ButtonStyle
{
    private const string BASE = 'inline-flex min-h-touch items-center justify-center gap-2 rounded-control '
        .'text-center text-[0.9375rem] leading-tight font-semibold no-underline transition-colors '
        .'disabled:cursor-not-allowed disabled:opacity-55';

    private const array VARIANTS = [
        // White text on --brand-button is at least 4.5:1 for every company (brand v2:
        // 4.51:1 Segurex, 6.57:1 SDC), so labels never go below the normal text size;
        // the neutral palette flips both colors in dark mode (resources/css/app.css).
        'primary' => 'bg-brand-button text-brand-on-button hover:bg-[color-mix(in_oklab,var(--color-brand-button),black_14%)]',
        'secondary' => 'border border-line-strong bg-surface text-fg hover:bg-subtle',
        'ghost' => 'text-fg-soft hover:bg-fg/[0.07] hover:text-fg',
        // --color-surface flips to dark in dark mode, where the failure color turns light.
        'danger' => 'bg-status-failure text-surface hover:bg-[color-mix(in_oklab,var(--color-status-failure),black_14%)]',
    ];

    public static function classes(string $variant, bool $iconOnly = false): string
    {
        return implode(' ', [
            self::BASE,
            self::VARIANTS[$variant] ?? self::VARIANTS['primary'],
            $iconOnly ? 'size-touch shrink-0' : 'px-4 py-2',
        ]);
    }
}
