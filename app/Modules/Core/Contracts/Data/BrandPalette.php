<?php

namespace App\Modules\Core\Contracts\Data;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use InvalidArgumentException;

/**
 * Brand colors of the active company (skill ryr-design, Part A.6).
 *
 * The layout injects toCssVariables() into <head> and every component reads
 * the --brand-* variables, so one design serves SGX, SDC and the neutral
 * group view. Components never hard-code company hex values. The palette
 * comes from the user's active company (CurrentCompany::palette()), never
 * from the domain of their e-mail address.
 *
 * Every color is a CSS hex color: "#" followed by 3, 6 or 8 hex digits.
 */
final readonly class BrandPalette
{
    private const string HEX_COLOR = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

    /**
     * @param  string  $primary  Main brand color (logo, highlights).
     * @param  string  $accent  Secondary color.
     * @param  string  $dark  Darkest brand shade (headers, strong text on tint).
     * @param  string  $button  Background of primary buttons (AA contrast with white text).
     * @param  string  $tint  Light background wash in light mode.
     * @param  string  $ink  Brand-colored text on $tint in light mode.
     * @param  string  $darkTint  Replaces $tint in dark mode.
     * @param  string  $darkInk  Replaces $ink in dark mode.
     *
     * @throws InvalidArgumentException When a value is not a hex color.
     */
    public function __construct(
        public string $primary,
        public string $accent,
        public string $dark,
        public string $button,
        public string $tint,
        public string $ink,
        public string $darkTint,
        public string $darkInk,
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
            primary: '#17191c',
            accent: '#5e6168',
            dark: '#17191c',
            button: '#17191c',
            tint: '#f0f0ec',
            ink: '#17191c',
            darkTint: '#2c2e33',
            darkInk: '#f2f2ef',
        );
    }

    /**
     * Official palette of a company, as defined by the ryr-design skill.
     */
    public static function official(CompanyCode $company): self
    {
        return match ($company) {
            CompanyCode::SGX => new self(
                primary: '#ff671f',
                accent: '#666666',
                dark: '#8f360b',
                button: '#c24c15',
                tint: '#feeee8',
                ink: '#8d350c',
                darkTint: '#432519',
                darkInk: '#ffa17d',
            ),
            CompanyCode::SDC => new self(
                primary: '#183f94',
                accent: '#3e60a8',
                dark: '#03256c',
                button: '#183f94',
                tint: '#ecf2fe',
                ink: '#183f94',
                darkTint: '#202d47',
                darkInk: '#97bafe',
            ),
        };
    }

    /**
     * The palette as CSS custom properties, in this order: --brand-primary,
     * --brand-accent, --brand-dark, --brand-button, --brand-tint and
     * --brand-ink. In dark mode, tint and ink take the dark variants.
     *
     * @return array<string, string>
     */
    public function toCssVariables(bool $dark = false): array
    {
        return [
            '--brand-primary' => $this->primary,
            '--brand-accent' => $this->accent,
            '--brand-dark' => $this->dark,
            '--brand-button' => $this->button,
            '--brand-tint' => $dark ? $this->darkTint : $this->tint,
            '--brand-ink' => $dark ? $this->darkInk : $this->ink,
        ];
    }
}
