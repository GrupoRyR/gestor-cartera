<?php

namespace App\Modules\Cobro\Application\Reports;

use Carbon\CarbonImmutable;

/**
 * The payment commitments a client registered in the DAYS days after a
 * mail of the collection report: from the moment it was sent (included)
 * to the same moment DAYS days later (excluded). Commitments belong to
 * the client, not to an invoice or a run, so every mail of the client in
 * that window counts the same commitment.
 */
final readonly class CommitmentWindow
{
    public const int DAYS = 7;

    /**
     * @param  array<int, list<CarbonImmutable>>  $createdAt  Creation moments by client id, oldest first
     *                                                        (CompromisoService::createdBetween()).
     */
    public function __construct(private array $createdAt = []) {}

    /**
     * The first commitment of the client in the window of a mail sent at
     * $sentAt; null without one or when the mail was not sent.
     */
    public function first(int $clientId, ?CarbonImmutable $sentAt): ?CarbonImmutable
    {
        return $this->within($clientId, $sentAt)[0] ?? null;
    }

    /**
     * How many commitments the client registered in the window of a mail
     * sent at $sentAt; null when the mail was not sent.
     */
    public function count(int $clientId, ?CarbonImmutable $sentAt): ?int
    {
        return $sentAt === null ? null : count($this->within($clientId, $sentAt));
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function within(int $clientId, ?CarbonImmutable $sentAt): array
    {
        if ($sentAt === null) {
            return [];
        }

        $end = $sentAt->addDays(self::DAYS);

        return array_values(array_filter(
            $this->createdAt[$clientId] ?? [],
            fn (CarbonImmutable $moment): bool => $moment->greaterThanOrEqualTo($sentAt) && $moment->lessThan($end),
        ));
    }
}
