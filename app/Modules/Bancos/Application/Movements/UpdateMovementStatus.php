<?php

namespace App\Modules\Bancos\Application\Movements;

use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\AuditLogger;
use InvalidArgumentException;

/**
 * Where a credit stands with the analyst: pending, applied in SAP (with the
 * cash receipt number) or ignored. Only an applied credit has a receipt; a
 * receipt can cover several payments, so it is free text such as "4521, 4522".
 *
 * Every change is audited with what it was and what it became.
 */
final class UpdateMovementStatus
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws InvalidArgumentException When a credit is marked applied without a receipt number.
     */
    public function handle(BankMovement $movement, MovementStatus $status, ?string $receiptNumber, ?int $userId): BankMovement
    {
        $receipt = $receiptNumber === null ? null : trim($receiptNumber);

        if ($status === MovementStatus::Applied && ($receipt === null || $receipt === '')) {
            throw new InvalidArgumentException('An applied movement needs its cash receipt number.');
        }

        $old = ['status' => $movement->status->value, 'receipt_number' => $movement->receipt_number];
        $applied = $status === MovementStatus::Applied;

        $new = ['status' => $status->value, 'receipt_number' => $applied ? $receipt : null];

        if ($old === $new) {
            return $movement;
        }

        $movement->update([
            ...$new,
            'applied_by' => $applied ? $userId : null,
            'applied_at' => $applied ? now() : null,
        ]);

        $changes = [];

        foreach ($new as $field => $value) {
            if ($old[$field] !== $value) {
                $changes[$field] = ['old' => $old[$field], 'new' => $value];
            }
        }

        $this->audit->record('bancos.movimiento.updated', 'bancos_movimiento', $movement->id, $changes, [
            'bank' => $movement->bank->value,
            'company' => $movement->company->value,
        ]);

        return $movement;
    }
}
