<?php

namespace App\Modules\Asesores\Domain\Agenda;

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use Carbon\CarbonImmutable;

/**
 * Sorts commitments into the groups of the agenda by calendar day: the hour
 * of the day never moves one.
 *
 * Core marks a pending commitment as broken shortly after midnight; until
 * then one whose day has passed already counts as broken here.
 */
final class AgendaPlanner
{
    /**
     * Days ahead that still count as "this week".
     */
    public const int WEEK_DAYS = 7;

    /**
     * @param  list<Commitment>  $commitments  Any status: the closed ones are left out.
     */
    public static function plan(array $commitments, CarbonImmutable $today): Agenda
    {
        $start = $today->startOfDay();
        $weekEnd = $start->addDays(self::WEEK_DAYS);
        $groups = ['broken' => [], 'today' => [], 'week' => [], 'later' => []];

        foreach ($commitments as $commitment) {
            $group = self::groupOf($commitment, $start, $weekEnd);

            if ($group !== null) {
                $groups[$group][] = $commitment;
            }
        }

        return new Agenda(
            broken: self::sorted($groups['broken']),
            today: self::sorted($groups['today']),
            week: self::sorted($groups['week']),
            later: self::sorted($groups['later']),
        );
    }

    private static function groupOf(Commitment $commitment, CarbonImmutable $today, CarbonImmutable $weekEnd): ?string
    {
        if ($commitment->status === CommitmentStatus::Broken) {
            return 'broken';
        }

        if ($commitment->status !== CommitmentStatus::Pending) {
            return null;
        }

        $day = $commitment->dueDate->startOfDay();

        return match (true) {
            $day < $today => 'broken',
            $day == $today => 'today',
            $day <= $weekEnd => 'week',
            default => 'later',
        };
    }

    /**
     * The oldest day first; the bigger amount first within a day.
     *
     * @param  list<Commitment>  $commitments
     * @return list<Commitment>
     */
    private static function sorted(array $commitments): array
    {
        usort($commitments, fn (Commitment $a, Commitment $b): int => $a->dueDate->startOfDay() <=> $b->dueDate->startOfDay()
            ?: $b->amount <=> $a->amount
            ?: $a->id <=> $b->id);

        return $commitments;
    }
}
