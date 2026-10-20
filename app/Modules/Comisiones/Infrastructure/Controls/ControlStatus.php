<?php

namespace App\Modules\Comisiones\Infrastructure\Controls;

/**
 * The outcome of a control of §9 on the controls screen (§7.8).
 */
enum ControlStatus: string
{
    case Meets = 'cumple';
    case Fails = 'no_cumple';
    case NotApplicable = 'no_aplica';

    public static function of(bool $meets): self
    {
        return $meets ? self::Meets : self::Fails;
    }

    public function label(): string
    {
        return __('comisiones::controles.estados.'.$this->value);
    }

    /** Chip tone: the text says it too, color never carries it alone. */
    public function tone(): string
    {
        return match ($this) {
            self::Meets => 'success',
            self::Fails => 'danger',
            self::NotApplicable => 'neutral',
        };
    }
}
