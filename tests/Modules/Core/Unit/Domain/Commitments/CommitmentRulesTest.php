<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Domain\Commitments\CommitmentRules;
use App\Modules\Core\Domain\Status\StatusDecision;
use Carbon\CarbonImmutable;

describe('broken commitments', function () {
    it('breaks a pending commitment only after its due date', function (string $dueDate, string $today, bool $expected) {
        $broken = CommitmentRules::isBroken(
            CommitmentStatus::Pending,
            CarbonImmutable::parse($dueDate, 'America/Bogota'),
            CarbonImmutable::parse($today, 'America/Bogota'),
        );

        expect($broken)->toBe($expected);
    })->with([
        'due yesterday' => ['2026-10-14', '2026-10-15', true],
        'due a month ago' => ['2026-09-15', '2026-10-15', true],
        'due today' => ['2026-10-15', '2026-10-15', false],
        'due tomorrow' => ['2026-10-16', '2026-10-15', false],
        'due today, checked late at night' => ['2026-10-15 00:00:00', '2026-10-15 23:59:59', false],
        'due late yesterday, checked just after midnight' => ['2026-10-14 23:59:59', '2026-10-15 00:00:01', true],
    ]);

    it('never breaks a commitment that is no longer pending', function (CommitmentStatus $status) {
        $broken = CommitmentRules::isBroken(
            $status,
            CarbonImmutable::parse('2026-09-15', 'America/Bogota'),
            CarbonImmutable::parse('2026-10-15', 'America/Bogota'),
        );

        expect($broken)->toBeFalse();
    })->with([CommitmentStatus::Fulfilled, CommitmentStatus::Broken, CommitmentStatus::Cancelled]);
});

describe('after a commitment is created', function () {
    it('moves the client to committed unless it is in legal or already committed', function (ClientStatus $current, StatusDecision $expected) {
        expect(CommitmentRules::afterCreate($current))->toEqual($expected);
    })->with([
        'current' => [ClientStatus::Current, fn () => StatusDecision::set(ClientStatus::Committed)],
        'due soon' => [ClientStatus::DueSoon, fn () => StatusDecision::set(ClientStatus::Committed)],
        'overdue' => [ClientStatus::Overdue, fn () => StatusDecision::set(ClientStatus::Committed)],
        'in progress' => [ClientStatus::InProgress, fn () => StatusDecision::set(ClientStatus::Committed)],
        'defaulted' => [ClientStatus::Defaulted, fn () => StatusDecision::set(ClientStatus::Committed)],
        'payment received' => [ClientStatus::PaymentReceived, fn () => StatusDecision::set(ClientStatus::Committed)],
        'already committed' => [ClientStatus::Committed, fn () => StatusDecision::keep()],
        'legal' => [ClientStatus::Legal, fn () => StatusDecision::keep()],
    ]);
});

describe('after a commitment breaks', function () {
    it('keeps the status while another commitment is pending', function (ClientStatus $current) {
        expect(CommitmentRules::afterBreak($current, hasOtherPending: true))->toEqual(StatusDecision::keep());
    })->with(ClientStatus::cases());

    it('moves the client to defaulted when no other commitment is pending', function (ClientStatus $current, StatusDecision $expected) {
        expect(CommitmentRules::afterBreak($current, hasOtherPending: false))->toEqual($expected);
    })->with([
        'current' => [ClientStatus::Current, fn () => StatusDecision::set(ClientStatus::Defaulted)],
        'due soon' => [ClientStatus::DueSoon, fn () => StatusDecision::set(ClientStatus::Defaulted)],
        'overdue' => [ClientStatus::Overdue, fn () => StatusDecision::set(ClientStatus::Defaulted)],
        'in progress' => [ClientStatus::InProgress, fn () => StatusDecision::set(ClientStatus::Defaulted)],
        'committed' => [ClientStatus::Committed, fn () => StatusDecision::set(ClientStatus::Defaulted)],
        'already defaulted' => [ClientStatus::Defaulted, fn () => StatusDecision::keep()],
        'payment received' => [ClientStatus::PaymentReceived, fn () => StatusDecision::keep()],
        'legal' => [ClientStatus::Legal, fn () => StatusDecision::keep()],
    ]);
});

describe('after a commitment is fulfilled or cancelled', function () {
    it('keeps the status while another commitment is pending', function (ClientStatus $current) {
        expect(CommitmentRules::afterClose($current, hasOtherPending: true))->toEqual(StatusDecision::keep());
    })->with(ClientStatus::cases());

    it('recomputes only a status the commitment workflow set', function (ClientStatus $current, StatusDecision $expected) {
        expect(CommitmentRules::afterClose($current, hasOtherPending: false))->toEqual($expected);
    })->with([
        'committed' => [ClientStatus::Committed, fn () => StatusDecision::recompute()],
        'defaulted' => [ClientStatus::Defaulted, fn () => StatusDecision::recompute()],
        'current' => [ClientStatus::Current, fn () => StatusDecision::keep()],
        'due soon' => [ClientStatus::DueSoon, fn () => StatusDecision::keep()],
        'overdue' => [ClientStatus::Overdue, fn () => StatusDecision::keep()],
        'in progress' => [ClientStatus::InProgress, fn () => StatusDecision::keep()],
        'payment received' => [ClientStatus::PaymentReceived, fn () => StatusDecision::keep()],
        'legal' => [ClientStatus::Legal, fn () => StatusDecision::keep()],
    ]);
});
