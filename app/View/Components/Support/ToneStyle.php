<?php

namespace App\View\Components\Support;

/**
 * Status colors of the tones the components accept (neutral, success,
 * warning, danger, info), shared by <x-ui.chip>, <x-ui.stat> and
 * <x-ui.alert> so a tone looks the same everywhere. The class names are
 * literal so Tailwind finds them (resources/css/app.css scans this file).
 */
final class ToneStyle
{
    /**
     * Soft fill with text in the tone color. A neutral chip is a brand label:
     * tint fill and ink text (skill frontend-marca-sgx-sydc-v2: 6.21:1 or more,
     * 4.74:1 or more in dark mode).
     */
    private const array SOFT = [
        'neutral' => 'bg-brand-tint text-brand-ink',
        'success' => 'bg-status-active-bg text-status-active',
        'warning' => 'bg-status-maintenance-bg text-status-maintenance',
        'danger' => 'bg-status-failure-bg text-status-failure',
        'info' => 'bg-status-scheduled-bg text-status-scheduled',
    ];

    /** Soft fill that keeps the text color, with the tone in --tone-accent for icons and titles. */
    private const array PANEL = [
        'neutral' => 'bg-status-neutral-bg [--tone-accent:var(--color-status-neutral)]',
        'success' => 'bg-status-active-bg [--tone-accent:var(--color-status-active)]',
        'warning' => 'bg-status-maintenance-bg [--tone-accent:var(--color-status-maintenance)]',
        'danger' => 'bg-status-failure-bg [--tone-accent:var(--color-status-failure)]',
        'info' => 'bg-status-scheduled-bg [--tone-accent:var(--color-status-scheduled)]',
    ];

    /** Solid dot drawn before a label (::before) in the tone color. */
    private const array LABEL_DOT = [
        'neutral' => 'before:bg-status-neutral',
        'success' => 'before:bg-status-active-dot',
        'warning' => 'before:bg-status-maintenance',
        'danger' => 'before:bg-status-failure',
        'info' => 'before:bg-status-scheduled',
    ];

    public static function soft(string $tone): string
    {
        return self::SOFT[$tone] ?? self::SOFT['neutral'];
    }

    public static function panel(string $tone): string
    {
        return self::PANEL[$tone] ?? self::PANEL['neutral'];
    }

    public static function labelDot(string $tone): string
    {
        return self::LABEL_DOT[$tone] ?? self::LABEL_DOT['neutral'];
    }
}
