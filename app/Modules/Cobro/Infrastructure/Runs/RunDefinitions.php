<?php

namespace App\Modules\Cobro\Infrastructure\Runs;

use App\Modules\Cobro\Application\Runs\RunDefinition;
use App\Modules\Cobro\Domain\Runs\RunKind;
use UnexpectedValueException;

/**
 * Every RunDefinition by kind. CobroServiceProvider registers them.
 */
final class RunDefinitions
{
    /**
     * @var array<string, RunDefinition>
     */
    private array $definitions = [];

    /**
     * @param  iterable<RunDefinition>  $definitions
     */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $this->definitions[$definition->kind()->value] = $definition;
        }
    }

    public function for(RunKind $kind): RunDefinition
    {
        return $this->definitions[$kind->value]
            ?? throw new UnexpectedValueException("No run definition is registered for {$kind->value}.");
    }

    /**
     * @return list<RunDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
