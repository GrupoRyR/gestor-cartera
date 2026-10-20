<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\Data\MailRunResults;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\MailRunKind;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * In-memory MailRunResultsProvider fed with add(), one call per run;
 * results() adds up the runs of the company and kind whose scheduled
 * moment falls, as a calendar day in America/Bogota, inside the period.
 */
final class FakeMailRunResultsProvider implements MailRunResultsProvider
{
    private const string TIMEZONE = 'America/Bogota';

    /**
     * @var list<array{day: string, results: MailRunResults}>
     */
    private array $runs = [];

    /**
     * Records one run scheduled at $scheduledFor with these client counts.
     *
     * @throws InvalidArgumentException When a count is negative, or there are more
     *                                  bounces or replies than mails sent.
     */
    public function add(
        CompanyCode $company,
        MailRunKind $kind,
        CarbonImmutable $scheduledFor,
        int $sent = 0,
        int $bounced = 0,
        int $withoutEmail = 0,
        int $failed = 0,
        int $replies = 0,
    ): self {
        $day = self::day($scheduledFor->setTimezone(self::TIMEZONE));

        $this->runs[] = [
            'day' => $day->toDateString(),
            'results' => new MailRunResults($company, $kind, $day, $day, 1, $sent, $bounced, $withoutEmail, $failed, $replies),
        ];

        return $this;
    }

    public function results(CompanyCode $company, MailRunKind $kind, CarbonImmutable $from, CarbonImmutable $to): MailRunResults
    {
        $firstDay = self::day($from);
        $lastDay = self::day($to);

        if ($firstDay->greaterThan($lastDay)) {
            throw new InvalidArgumentException("The period ends ({$to->toDateString()}) before it starts ({$from->toDateString()}).");
        }

        $total = MailRunResults::empty($company, $kind, $firstDay, $lastDay);

        foreach ($this->runs as $run) {
            $results = $run['results'];

            if ($results->company !== $company || $results->kind !== $kind
                || $run['day'] < $firstDay->toDateString() || $run['day'] > $lastDay->toDateString()) {
                continue;
            }

            $total = new MailRunResults(
                company: $company,
                kind: $kind,
                from: $firstDay,
                to: $lastDay,
                runs: $total->runs + 1,
                sent: $total->sent + $results->sent,
                bounced: $total->bounced + $results->bounced,
                withoutEmail: $total->withoutEmail + $results->withoutEmail,
                failed: $total->failed + $results->failed,
                replies: $total->replies + $results->replies,
            );
        }

        return $total;
    }

    /**
     * The calendar day of $date, at its start in Bogotá.
     */
    private static function day(CarbonImmutable $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString(), self::TIMEZONE)->startOfDay();
    }
}
