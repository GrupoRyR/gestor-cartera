<?php

namespace App\Modules\Soportes\Application\Review;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Soportes\Domain\Exceptions\InvalidReceiptTransition;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The portfolio analyst's decision on a pending receipt: applied (the
 * payment is in SAP, with an optional cash receipt number) or rejected
 * (with the reason the advisor sees). Who may decide is ReceiptPolicy::review;
 * this service only keeps the rule and the record.
 *
 * The update is conditional on the row still being pending, inside the same
 * transaction as the audit entry, so when two reviewers decide at once only
 * the first one counts and the second gets InvalidReceiptTransition. The app
 * never marks an invoice as paid and sends no e-mail.
 */
final readonly class ReviewReceipt
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws InvalidReceiptTransition When the receipt is no longer pending.
     */
    public function apply(Receipt $receipt, User $reviewer, ?string $receiptNumber): void
    {
        $this->decide($receipt, ReceiptStatus::Applied, $reviewer, 'soportes.receipt.applied', [
            'receipt_number' => $receiptNumber,
        ]);
    }

    /**
     * @throws InvalidReceiptTransition When the receipt is no longer pending.
     */
    public function reject(Receipt $receipt, User $reviewer, string $reason): void
    {
        $this->decide($receipt, ReceiptStatus::Rejected, $reviewer, 'soportes.receipt.rejected', [
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, string|null>  $fields  The decision's own columns.
     *
     * @throws InvalidReceiptTransition
     */
    private function decide(Receipt $receipt, ReceiptStatus $to, User $reviewer, string $action, array $fields): void
    {
        $from = $receipt->status;
        $to = $from->moveTo($to);
        $reviewedAt = CarbonImmutable::now();

        DB::transaction(function () use ($receipt, $from, $to, $reviewer, $reviewedAt, $action, $fields): void {
            $updated = Receipt::query()
                ->whereKey($receipt->id)
                ->where('status', ReceiptStatus::Pending->value)
                ->update([
                    ...$fields,
                    'status' => $to->value,
                    'reviewed_by' => $reviewer->getKey(),
                    'reviewed_at' => $reviewedAt,
                ]);

            if ($updated !== 1) {
                throw InvalidReceiptTransition::alreadyReviewed($receipt->id, $to);
            }

            $changes = ['status' => ['old' => $from->value, 'new' => $to->value]];

            foreach ($fields as $field => $value) {
                $changes[$field] = ['old' => $receipt->getAttribute($field), 'new' => $value];
            }

            $this->audit->record($action, 'soporte', $receipt->id, $changes, [
                'company' => $receipt->company->value,
                'client_id' => $receipt->client_id,
            ]);
        });

        $receipt->refresh();
    }
}
