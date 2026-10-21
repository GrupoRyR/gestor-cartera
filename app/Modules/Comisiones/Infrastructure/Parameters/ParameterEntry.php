<?php

namespace App\Modules\Comisiones\Infrastructure\Parameters;

use App\Modules\Comisiones\Infrastructure\Persistence\ParameterRecord;
use LogicException;

/**
 * One key of the parameter screen on a date: the version in force that day
 * and the first one that starts after it. At least one of both is set.
 */
final readonly class ParameterEntry
{
    public function __construct(
        public string $key,
        public ?ParameterRecord $current,
        public ?ParameterRecord $next,
    ) {}

    /**
     * The version whose values the screen shows: the one in force, else the
     * one that starts later (a key added from a future date).
     */
    public function shown(): ParameterRecord
    {
        return $this->current ?? $this->next ?? throw new LogicException("Parameter {$this->key} has no version to show.");
    }
}
