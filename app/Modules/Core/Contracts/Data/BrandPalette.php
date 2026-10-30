<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use InvalidArgumentException;

/**
 * Brand colors of the active company (skill frontend-marca-sgx-sydc-v2).
 *
 * The layout injects toCssVariables() into <head> and every component reads
 * the --brand-* variables, so one design serves SGX, SDC and the neutral
 * group view. Components never hard-code company hex values. The palette
 * comes from the user's active company (CurrentCompany::palette()), never
 * from the domain of their e-mail address.
 *
 * Every color is a CSS hex color: "#" followed by 3, 6 or 8 hex digits
 * (8 digits carry the alpha of the translucent dark tints).
 */
final readonly class BrandPalette
{
    private const string HEX_COLOR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    /**
     * @param  string  $accent  Icons, bars and borders; as text, only when large.
     * @param  string  $button  Background of buttons with white text.
     * @param  string  $ink  Brand-colored text on the surface and on $tint.
     * @param  string  $text  Long text.
     * @param  string  $tint  Background of chips, labels and notices.
     * @param  string  $head  Background of the header, with white text.
     * @param  string  $darkAccent  Replaces $accent in dark mode.
     * @param  string  $darkInk  Replaces $ink in dark mode.
     * @param  string  $darkText  Replaces $text in dark mode.
     * @param  string  $darkTint  Replaces $tint in dark mode.
     * @param  string  $darkHead  Replaces $head in dark mode.
     *
     * @throws InvalidArgumentException When a value is not a hex color.
     */
    public function __construct(
        public string $accent,
        public string $button,
        public string $ink,
        public string $text,
        public string $tint,
        public string $head,
        public string $darkAccent,
        public string $darkInk,
        public string $darkText,
        public string $darkTint,
        public string $darkHead,
    ) {
        foreach (get_object_vars($this) as $name => $value) {
            if (preg_match(self::HEX_COLOR, $value) !== 1) {
                throw new InvalidArgumentException("Brand color [{$name}] must be a hex color, [{$value}] given.");
            }
        }
    }

    /**
     * Neutral palette for the group view and for guests.
     */
    public static function neutral(): self
    {
        return new self(
            accent: '#5e6168',
            button: '#17191c',
            ink: '#17191c',
            text: '#45484e',
            tint: '#f0f0ec',
            head: '#26272b',
            darkAccent: '#a3a6ad',
            darkInk: '#f2f2ef',
            darkText: '#cacbcd',
            darkTint: '#2c2e33',
            darkHead: '#26272b',
        );
    }

    /**
     * Official palette of a company, as defined by the frontend-marca-sgx-sydc-v2 skill.
     */
    public static function official(CompanyCode $company): self
    {
        return match ($company) {
            CompanyCode::SGX => new self(
                accent: '#f26522',
                button: '#c6531c',
                ink: '#943e15',
                text: '#58595b',
                tint: '#fcede4',
                head: '#58595b',
                darkAccent: '#f26522',
                darkInk: '#f26522',
                darkText: '#cacbcd',
                darkTint: '#f265221a',
                darkHead: '#2b2c2f',
            ),
            CompanyCode::SDC => new self(
                accent: '#0b5fa5',
                button: '#0b5fa5',
                ink: '#073b66',
                text: '#073b66',
                tint: '#e5eef7',
                head: '#073b66',
                darkAccent: '#5e9ad0',
                darkInk: '#5e9ad0',
                darkText: '#cacbcd',
                darkTint: '#5e9ad01c',
                darkHead: '#0a2540',
            ),
        };
    }

    /**
     * The palette as CSS custom properties, in this order: --brand-accent,
     * --brand-button, --brand-ink, --brand-text, --brand-tint and
     * --brand-head. In dark mode every one but the button takes its dark
     * variant.
     *
     * @return array<string, string>
     */
    public function toCssVariables(bool $dark = false): array
    {
        return [
            '--brand-accent' => $dark ? $this->darkAccent : $this->accent,
            '--brand-button' => $this->button,
            '--brand-ink' => $dark ? $this->darkInk : $this->ink,
            '--brand-text' => $dark ? $this->darkText : $this->text,
            '--brand-tint' => $dark ? $this->darkTint : $this->tint,
            '--brand-head' => $dark ? $this->darkHead : $this->head,
        ];
    }
}
