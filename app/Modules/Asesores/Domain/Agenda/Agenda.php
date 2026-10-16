<?php

namespace App\Modules\Asesores\Domain\Agenda;

use App\Modules\Core\Contracts\Data\Commitment;

/**
 * The open commitments of an advisor, grouped by when they matter.
 */
final readonly class Agenda
{
    /**
     * @param  list<Commitment>  $broken  Not paid on the promised day: the ones to chase first.
     * @param  list<Commitment>  $today  Due today.
     * @param  list<Commitment>  $week  Due in the next seven days.
     * @param  list<Commitment>  $later  Due after that.
     */
    public function __construct(
        public array $broken,
        public array $today,
        public array $week,
        public array $later,
    ) {}

    /**
     * The groups in the order the screen shows them.
     *
     * @return array<string, list<Commitment>>
     */
    public function groups(): array
    {
        return [
            'broken' => $this->broken,
            'today' => $this->today,
            'week' => $this->week,
            'later' => $this->later,
        ];
    }

    /**
     * How many open commitments there are.
     */
    public function total(): int
    {
        return count($this->broken) + count($this->today) + count($this->week) + count($this->later);
    }

    /**
     * The pesos promised in one group ("broken", "today", "week" or "later").
     */
    public function amountOf(string $group): float
    {
        $cents = 0;

        foreach ($this->groups()[$group] ?? [] as $commitment) {
            $cents += (int) round($commitment->amount * 100);
        }

        return $cents / 100;
    }
}
