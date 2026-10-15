<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Domain\Status\ClientStatusRules;
use App\Modules\Core\Domain\Status\OpenItem;
use App\Modules\Core\Domain\Status\StatusParameters;

describe('status families', function () {
    it('lists the manual statuses', function () {
        expect(ClientStatusRules::MANUAL)->toBe([
            ClientStatus::InProgress,
            ClientStatus::Committed,
            ClientStatus::Defaulted,
            ClientStatus::Legal,
        ]);
    });

    it('lists the automatic statuses', function () {
        expect(ClientStatusRules::AUTOMATIC)->toBe([
            ClientStatus::Current,
            ClientStatus::DueSoon,
            ClientStatus::Overdue,
            ClientStatus::PaymentReceived,
        ]);
    });

    it('puts every status in exactly one family', function (ClientStatus $status) {
        expect(ClientStatusRules::isManual($status))->not->toBe(ClientStatusRules::isAutomatic($status))
            ->and(ClientStatusRules::isManual($status))->toBe(in_array($status, ClientStatusRules::MANUAL, true));
    })->with(ClientStatus::cases());
});

describe('resolve from an automatic status', function () {
    it('counts the grace days after the due date', function (int $graceDays, int $daysOverdue, ClientStatus $expected) {
        $status = ClientStatusRules::resolve(
            ClientStatus::Current,
            [new OpenItem(100_000.0, $daysOverdue)],
            new StatusParameters(graceDays: $graceDays, dueSoonDays: 5),
        );

        expect($status)->toBe($expected);
    })->with([
        'SGX, 15 days past due' => [15, 15, ClientStatus::DueSoon],
        'SGX, 16 days past due' => [15, 16, ClientStatus::Overdue],
        'SDC, 5 days past due' => [5, 5, ClientStatus::DueSoon],
        'SDC, 6 days past due' => [5, 6, ClientStatus::Overdue],
    ]);

    it('marks a client due soon inside the due soon window', function (int $daysOverdue, ClientStatus $expected) {
        $status = ClientStatusRules::resolve(
            ClientStatus::Current,
            [new OpenItem(100_000.0, $daysOverdue)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe($expected);
    })->with([
        'due in 6 days' => [-6, ClientStatus::Current],
        'due in 5 days' => [-5, ClientStatus::DueSoon],
        'due tomorrow' => [-1, ClientStatus::DueSoon],
        'due today' => [0, ClientStatus::DueSoon],
        'one day past due, inside the grace days' => [1, ClientStatus::DueSoon],
    ]);

    it('picks the worst status among the invoices', function () {
        $status = ClientStatusRules::resolve(
            ClientStatus::DueSoon,
            [new OpenItem(50_000.0, -30), new OpenItem(20_000.0, -2), new OpenItem(10_000.0, 20)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::Overdue);
    });

    it('moves an overdue client back to current once the old invoices are paid', function () {
        $status = ClientStatusRules::resolve(
            ClientStatus::Overdue,
            [new OpenItem(0.0, 40), new OpenItem(500_000.0, -10)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::Current);
    });

    it('ignores a credit note when looking for overdue invoices', function () {
        $status = ClientStatusRules::resolve(
            ClientStatus::Current,
            [new OpenItem(-200_000.0, 40), new OpenItem(500_000.0, -10)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::Current);
    });

    it('marks payment received when a credit note offsets an overdue invoice', function () {
        $status = ClientStatusRules::resolve(
            ClientStatus::Overdue,
            [new OpenItem(150_000.0, 40), new OpenItem(-150_000.0, 3)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::PaymentReceived);
    });

    it('treats a balance within the tolerance as paid', function (float $balance, ClientStatus $expected) {
        $status = ClientStatusRules::resolve(
            ClientStatus::Overdue,
            [new OpenItem($balance, 40)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe($expected);
    })->with([
        'one cent' => [0.01, ClientStatus::PaymentReceived],
        'two cents' => [0.02, ClientStatus::Overdue],
    ]);

    it('marks payment received when an automatic status reaches zero balance', function (ClientStatus $current) {
        $status = ClientStatusRules::resolve(
            $current,
            [new OpenItem(0.0, 10)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::PaymentReceived);
    })->with([ClientStatus::DueSoon, ClientStatus::Overdue, ClientStatus::PaymentReceived]);

    it('keeps a current client current with zero balance', function (array $items) {
        $status = ClientStatusRules::resolve(
            ClientStatus::Current,
            $items,
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::Current);
    })->with([
        'no items' => [fn () => []],
        'a settled invoice' => [fn () => [new OpenItem(0.0, 10)]],
        'only a credit note' => [fn () => [new OpenItem(-50_000.0, 10)]],
    ]);

    it('treats an empty list as zero balance', function () {
        $status = ClientStatusRules::resolve(
            ClientStatus::Overdue,
            [],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::PaymentReceived);
    });

    it('marks payment received when only credit notes remain', function () {
        $status = ClientStatusRules::resolve(
            ClientStatus::Overdue,
            [new OpenItem(-50_000.0, 10), new OpenItem(-1_000.0, -3)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::PaymentReceived);
    });

    it('recomputes payment received when new debt appears', function (int $daysOverdue, ClientStatus $expected) {
        $status = ClientStatusRules::resolve(
            ClientStatus::PaymentReceived,
            [new OpenItem(80_000.0, $daysOverdue)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe($expected);
    })->with([
        'not due for a while' => [-20, ClientStatus::Current],
        'due soon' => [-3, ClientStatus::DueSoon],
        'past the grace days' => [20, ClientStatus::Overdue],
    ]);
});

describe('resolve from a manual status', function () {
    it('keeps a manual status while the client owes money', function (ClientStatus $current, array $items) {
        $status = ClientStatusRules::resolve(
            $current,
            $items,
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe($current);
    })->with([
        ClientStatus::InProgress,
        ClientStatus::Committed,
        ClientStatus::Defaulted,
        ClientStatus::Legal,
    ])->with([
        'nothing due yet' => [fn () => [new OpenItem(100_000.0, -30)]],
        'an overdue invoice' => [fn () => [new OpenItem(100_000.0, 40)]],
    ]);

    it('overrides a manual status with payment received at zero balance', function (ClientStatus $current) {
        $status = ClientStatusRules::resolve(
            $current,
            [new OpenItem(100_000.0, 40), new OpenItem(-100_000.0, 0)],
            new StatusParameters(graceDays: 15, dueSoonDays: 5),
        );

        expect($status)->toBe(ClientStatus::PaymentReceived);
    })->with([
        ClientStatus::InProgress,
        ClientStatus::Committed,
        ClientStatus::Defaulted,
        ClientStatus::Legal,
    ]);
});

describe('overdue for status', function () {
    it('needs a positive balance past the grace days', function (float $balance, int $daysOverdue, bool $expected) {
        $params = new StatusParameters(graceDays: 15, dueSoonDays: 5);

        expect(ClientStatusRules::overdueForStatus(new OpenItem($balance, $daysOverdue), $params))->toBe($expected);
    })->with([
        'past the grace days' => [100_000.0, 16, true],
        'on the last grace day' => [100_000.0, 15, false],
        'a credit note past the grace days' => [-100_000.0, 40, false],
        'a settled invoice past the grace days' => [0.0, 40, false],
    ]);
});
