<?php

namespace App\View\Components\Support;

/**
 * Interface icons drawn for <x-ui.icon> (phase 0b, Diseño).
 *
 * Every icon sits on a 24 × 24 grid with a 1.75 stroke and round caps and
 * joins (skill ryr-design), and paints with currentColor, so it takes the
 * color of the text around it. The keys are the vocabulary of the `icon`
 * prop of the components and of NavItem::$icon; the gallery at
 * /dev/componentes shows all of them. An unknown name draws nothing.
 */
final class IconSet
{
    /**
     * SVG children of each icon, keyed by name.
     *
     * @var array<string, string>
     */
    private const array ICONS = [
        // Navigation and places
        'home' => '<path d="M4 10.5 12 4l8 6.5V19a1.5 1.5 0 0 1-1.5 1.5H15v-6H9v6H5.5A1.5 1.5 0 0 1 4 19z"/>',
        'dashboard' => '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="4.5" rx="1.5"/><rect x="13" y="11" width="7" height="9" rx="1.5"/><rect x="4" y="13.5" width="7" height="6.5" rx="1.5"/>',
        'board' => '<rect x="3.5" y="4" width="4.5" height="12" rx="1.5"/><rect x="9.75" y="4" width="4.5" height="16" rx="1.5"/><rect x="16" y="4" width="4.5" height="8.5" rx="1.5"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19.5a6.5 6.5 0 0 1 13 0"/><path d="M15.5 4.7a3.5 3.5 0 0 1 0 6.6"/><path d="M18 14a6.5 6.5 0 0 1 3.5 5.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/>',
        'company' => '<path d="M3.5 20.5h17"/><path d="M6 20.5V5A1.5 1.5 0 0 1 7.5 3.5h9A1.5 1.5 0 0 1 18 5v15.5"/><path d="M9.5 8h1M13.5 8h1M9.5 12h1M13.5 12h1"/><path d="M10.5 20.5v-4h3v4"/>',
        'bank' => '<path d="M3 9.5 12 4l9 5.5"/><path d="M4 9.5h16"/><path d="M6 12.5v5M10 12.5v5M14 12.5v5M18 12.5v5"/><path d="M3.5 20.5h17"/>',
        'wallet' => '<path d="M17 7V5.5A1.5 1.5 0 0 0 15.5 4h-10A2 2 0 0 0 3.5 6"/><path d="M3.5 6v12a2 2 0 0 0 2 2h13a1.5 1.5 0 0 0 1.5-1.5v-10A1.5 1.5 0 0 0 18.5 7h-13a2 2 0 0 1-2-1"/><path d="M20 11.5h-3.5a1.75 1.75 0 0 0 0 3.5H20"/>',
        'money' => '<rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.75"/><path d="M6 9.5h.01M18 14.5h.01"/>',
        'chart' => '<path d="M4 20h16"/><path d="M7 16.5v-5M12 16.5V6M17 16.5v-8"/>',
        'document' => '<path d="M14 3.5H7a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8.5z"/><path d="M14 3.5v5h5"/><path d="M9 13h6M9 17h4"/>',
        'legal' => '<path d="M12 4v16M8 20h8M5 7.5h14"/><path d="M5 7.5 2.5 13.5a2.5 2.5 0 0 0 5 0z"/><path d="M19 7.5l-2.5 6a2.5 2.5 0 0 0 5 0z"/>',
        'sliders' => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',

        // Time
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M3.5 10h17"/><path d="M8 3v4M16 3v4"/>',
        'calendar-check' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M8 3v4M16 3v4"/><path d="m9 14.5 2 2 4-4"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'history' => '<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6L3.5 8.5"/><path d="M3.5 4v4.5H8"/><path d="M12 8v4l3 2"/>',

        // Messages
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
        'inbox' => '<path d="M3.5 13.5 6.1 6.3A2 2 0 0 1 8 5h8a2 2 0 0 1 1.9 1.3l2.6 7.2V18a2 2 0 0 1-2 2h-13a2 2 0 0 1-2-2z"/><path d="M3.5 13.5H8l1.5 2.5h5l1.5-2.5h4.5"/>',
        'send' => '<path d="M21 3 10 14"/><path d="M21 3 14.5 21 10 14 3 9.5z"/>',
        'phone' => '<path d="M5 4h3.5L10 8.5 8 10a11 11 0 0 0 6 6l1.5-2 4.5 1.5V19a1.5 1.5 0 0 1-1.6 1.5A16 16 0 0 1 3.5 5.6 1.5 1.5 0 0 1 5 4z"/>',

        // Actions
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'edit' => '<path d="M4 20l1-4L16 5a2.12 2.12 0 0 1 3 3L8 19z"/><path d="m14 7 3 3"/>',
        'trash' => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="m6 7 1 12a2 2 0 0 0 2 1.8h6a2 2 0 0 0 2-1.8L18 7"/><path d="M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'download' => '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
        'upload' => '<path d="M12 16V5"/><path d="m7 10 5-5 5 5"/><path d="M5 20h14"/>',
        'refresh' => '<path d="M20 12a8 8 0 1 1-2.34-5.66L20 8.5"/><path d="M20 4v4.5h-4.5"/>',
        'eye' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'external' => '<path d="M14 4h6v6"/><path d="m20 4-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'logout' => '<path d="M9 20H5.5a1.5 1.5 0 0 1-1.5-1.5v-13A1.5 1.5 0 0 1 5.5 4H9"/><path d="m16 16 4-4-4-4"/><path d="M20 12H9"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'more' => '<circle cx="5.5" cy="12" r="1.25" fill="currentColor"/><circle cx="12" cy="12" r="1.25" fill="currentColor"/><circle cx="18.5" cy="12" r="1.25" fill="currentColor"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',

        // Direction and order
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-up' => '<path d="m6 15 6-6 6 6"/>',
        'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'sort' => '<path d="m8 9 4-4 4 4"/><path d="m8 15 4 4 4-4"/>',
        'sort-asc' => '<path d="M12 19V5"/><path d="m6 11 6-6 6 6"/>',
        'sort-desc' => '<path d="M12 5v14"/><path d="m6 13 6 6 6-6"/>',
        'trend-up' => '<path d="m3 17 6-6 4 4 8-8"/><path d="M15 7h6v6"/>',
        'trend-down' => '<path d="m3 7 6 6 4-4 8 8"/><path d="M15 17h6v-6"/>',
        'trend-flat' => '<path d="M3 12h17"/><path d="m16 8 4 4-4 4"/>',

        // Feedback
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5"/><path d="M12 7.75h.01"/>',
        'success' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        'warning' => '<path d="M12 3.5 21.5 20h-19z"/><path d="M12 10v4.5"/><path d="M12 17.25h.01"/>',
        'danger' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5v5"/><path d="M12 16.25h.01"/>',
        'offline' => '<path d="M12 19.5h.01"/><path d="M8.8 16a4.5 4.5 0 0 1 6.4 0"/><path d="M5.5 12.6a9 9 0 0 1 4-2.3"/><path d="M18.5 12.6a9 9 0 0 0-1.9-1.3"/><path d="M2.5 9.2a13.5 13.5 0 0 1 3.9-2.5"/><path d="M21.5 9.2A13.5 13.5 0 0 0 11 5.6"/><path d="m3.5 3.5 17 17"/>',
    ];

    /**
     * Every icon name, in the order of the set.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::ICONS);
    }

    public static function has(string $name): bool
    {
        return isset(self::ICONS[$name]);
    }

    /**
     * The icon as an inline SVG that fills its box, hidden from assistive
     * technology (the wrapping <x-ui.icon> carries the accessible name, if
     * any). Null for an unknown name.
     */
    public static function svg(string $name): ?string
    {
        if (! self::has($name)) {
            return null;
        }

        return '<svg viewBox="0 0 24 24" width="100%" height="100%" fill="none" stroke="currentColor" stroke-width="1.75" '
            .'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            .self::ICONS[$name]
            .'</svg>';
    }
}
