<?php

namespace Tests\Modules\Comisiones\Support;

use App\Modules\Comisiones\Application\Ports\SettlementInputs;
use App\Modules\Comisiones\Domain\Input\SettlementInput;
use App\Modules\Comisiones\Domain\Shared\Period;

/**
 * The SAP lines and manual captures of each period, as a test sets them. A
 * period without input settles on nothing.
 */
final class FixedInputs implements SettlementInputs
{
    /** @var array<string, SettlementInput> By period key. */
    private array $inputs = [];

    public function __construct(SettlementInput ...$inputs)
    {
        foreach ($inputs as $input) {
            $this->put($input);
        }
    }

    /** Sets, or replaces, the input of its period. */
    public function put(SettlementInput $input): void
    {
        $this->inputs[$input->period->key()] = $input;
    }

    public function forPeriod(Period $period): SettlementInput
    {
        return $this->inputs[$period->key()] ?? new SettlementInput($period);
    }
}
