<?php

namespace App\Modules\Core\Contracts\Enums\Concerns;

/**
 * Select options for the string-backed contract enums.
 *
 * Every contract enum has a Spanish, sentence-case label for the user
 * interface; this trait turns its cases into value => label pairs for
 * <x-ui.select> and validation rules.
 */
trait HasOptions
{
    /**
     * Spanish label of the case for the user interface (sentence case).
     */
    abstract public function label(): string;

    /**
     * Every case as value => label, in declaration order.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
