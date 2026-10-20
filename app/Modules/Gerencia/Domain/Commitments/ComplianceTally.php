<?php

namespace App\Modules\Gerencia\Domain\Commitments;

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;

/**
 * Groups commitments by (company, advisor) and classifies each one against
 * today's date.
 */
final class ComplianceTally
{
    /**
     * A commitment counts as fulfilled on time when it was paid on or before
     * its due date (calendar days); one fulfilled without a payment date
     * cannot be shown to be on time.
     *
     * @param  iterable<Commitment>  $commitments
     * @return list<AdvisorCompliance> Lowest rate first (advisors with nothing evaluated last),
     *                                 then larger evaluated amount, company and advisor code.
     */
    public static function fromCommitments(iterable $commitments, CarbonImmutable $today): array
    {
        /** @var array<string, list<Commitment>> $groups */
        $groups = [];

        foreach ($commitments as $commitment) {
            $groups[$commitment->company->value.'|'.($commitment->advisorCode ?? 'none')][] = $commitment;
        }

        $rows = array_values(array_map(
            fn (array $group): AdvisorCompliance => self::summarize($group, $today->toDateString()),
            $groups,
        ));

        usort($rows, fn (AdvisorCompliance $a, AdvisorCompliance $b): int => self::sortKey($a) <=> self::sortKey($b));

        return $rows;
    }

    /**
     * Fulfilled share of everything evaluated across the rows, from 0 to
     * 100; null when nothing was evaluated.
     *
     * @param  list<AdvisorCompliance>  $rows
     */
    public static function overallRate(array $rows): ?float
    {
        $fulfilled = array_sum(array_map(fn (AdvisorCompliance $row): int => $row->fulfilled, $rows));
        $evaluated = array_sum(array_map(fn (AdvisorCompliance $row): int => $row->evaluated(), $rows));

        return $evaluated === 0 ? null : $fulfilled / $evaluated * 100;
    }

    /**
     * @param  non-empty-list<Commitment>  $group  Commitments of one company and advisor.
     */
    private static function summarize(array $group, string $today): AdvisorCompliance
    {
        $counts = ['fulfilled' => 0, 'onTime' => 0, 'broken' => 0, 'overduePending' => 0, 'upcoming' => 0, 'cancelled' => 0];
        $amountEvaluated = 0.0;
        $amountFulfilled = 0.0;

        foreach ($group as $commitment) {
            $kind = match ($commitment->status) {
                CommitmentStatus::Fulfilled => 'fulfilled',
                CommitmentStatus::Broken => 'broken',
                CommitmentStatus::Pending => $commitment->dueDate->toDateString() < $today ? 'overduePending' : 'upcoming',
                CommitmentStatus::Cancelled => 'cancelled',
            };

            $counts[$kind]++;

            if (in_array($kind, ['fulfilled', 'broken', 'overduePending'], true)) {
                $amountEvaluated += $commitment->amount;
            }

            if ($kind === 'fulfilled') {
                $amountFulfilled += $commitment->amount;

                if ($commitment->fulfilledAt !== null
                    && $commitment->fulfilledAt->toDateString() <= $commitment->dueDate->toDateString()) {
                    $counts['onTime']++;
                }
            }
        }

        return new AdvisorCompliance(
            company: $group[0]->company,
            advisorCode: $group[0]->advisorCode,
            fulfilled: $counts['fulfilled'],
            fulfilledOnTime: $counts['onTime'],
            broken: $counts['broken'],
            overduePending: $counts['overduePending'],
            upcoming: $counts['upcoming'],
            cancelled: $counts['cancelled'],
            amountEvaluated: $amountEvaluated,
            amountFulfilled: $amountFulfilled,
        );
    }

    /**
     * @return array{int, float, float, int, int}
     */
    private static function sortKey(AdvisorCompliance $row): array
    {
        $rate = $row->rate();

        return [
            $rate === null ? 1 : 0,
            $rate ?? 0.0,
            -$row->amountEvaluated,
            (int) array_search($row->company, CompanyCode::cases(), true),
            $row->advisorCode ?? PHP_INT_MAX,
        ];
    }
}
