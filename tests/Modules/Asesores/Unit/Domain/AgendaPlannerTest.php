<?php

use App\Modules\Asesores\Domain\Agenda\AgendaPlanner;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;

/**
 * An open commitment due on $date ("Y-m-d", Bogotá).
 */
function asesoresDueOn(string $date, CommitmentStatus $status = CommitmentStatus::Pending, float $amount = 1_000_000.0): Commitment
{
    return Make::commitment(amount: $amount, dueDate: CarbonImmutable::parse($date, 'America/Bogota')->startOfDay(), status: $status);
}

/**
 * The amounts of one group, to tell the commitments apart in the assertions.
 *
 * @param  list<Commitment>  $commitments
 * @return list<float>
 */
function asesoresAmounts(array $commitments): array
{
    return array_map(fn ($commitment): float => $commitment->amount, $commitments);
}

beforeEach(function () {
    $this->today = CarbonImmutable::parse('2026-10-16 15:30', 'America/Bogota');
});

it('puts each open commitment in the group of its date', function () {
    $agenda = AgendaPlanner::plan([
        asesoresDueOn('2026-10-05', CommitmentStatus::Broken, 1.0),
        asesoresDueOn('2026-10-16', amount: 2.0),
        asesoresDueOn('2026-10-17', amount: 3.0),
        asesoresDueOn('2026-10-23', amount: 4.0),
        asesoresDueOn('2026-10-24', amount: 5.0),
        asesoresDueOn('2026-12-01', amount: 6.0),
    ], $this->today);

    expect(asesoresAmounts($agenda->broken))->toBe([1.0])
        ->and(asesoresAmounts($agenda->today))->toBe([2.0])
        ->and(asesoresAmounts($agenda->week))->toBe([3.0, 4.0])
        ->and(asesoresAmounts($agenda->later))->toBe([5.0, 6.0])
        ->and($agenda->total())->toBe(6);
});

it('counts a pending commitment whose day has passed as broken, before Core marks it overnight', function () {
    $agenda = AgendaPlanner::plan([asesoresDueOn('2026-10-15', amount: 7.0), asesoresDueOn('2026-10-01', CommitmentStatus::Broken, 8.0)], $this->today);

    expect(asesoresAmounts($agenda->broken))->toBe([8.0, 7.0])
        ->and($agenda->today)->toBe([]);
});

it('leaves the closed commitments out', function () {
    $agenda = AgendaPlanner::plan([
        asesoresDueOn('2026-10-16', CommitmentStatus::Fulfilled),
        asesoresDueOn('2026-10-16', CommitmentStatus::Cancelled),
    ], $this->today);

    expect($agenda->total())->toBe(0);
});

it('orders each group by date, the oldest first, and by amount when the day is the same', function () {
    $agenda = AgendaPlanner::plan([
        asesoresDueOn('2026-10-20', amount: 10.0),
        asesoresDueOn('2026-10-18', amount: 20.0),
        asesoresDueOn('2026-10-18', amount: 30.0),
    ], $this->today);

    expect(asesoresAmounts($agenda->week))->toBe([30.0, 20.0, 10.0]);
});

it('adds up the amount of a group', function () {
    $agenda = AgendaPlanner::plan([asesoresDueOn('2026-10-17', amount: 1_500_000.0), asesoresDueOn('2026-10-18', amount: 2_500_000.0)], $this->today);

    expect($agenda->amountOf('week'))->toBe(4_000_000.0)
        ->and($agenda->amountOf('broken'))->toBe(0.0);
});

it('has the groups in the order the screen shows them', function () {
    expect(array_keys(AgendaPlanner::plan([], $this->today)->groups()))->toBe(['broken', 'today', 'week', 'later']);
});

it('does not move a commitment because of the hour of the day', function () {
    $lateNight = CarbonImmutable::parse('2026-10-16 23:59', 'America/Bogota');
    $earlyMorning = CarbonImmutable::parse('2026-10-16 00:01', 'America/Bogota');

    foreach ([$lateNight, $earlyMorning] as $moment) {
        expect(AgendaPlanner::plan([asesoresDueOn('2026-10-16')], $moment)->today)->toHaveCount(1);
    }
});
