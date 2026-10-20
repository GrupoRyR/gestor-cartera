<?php

namespace App\Modules\Comisiones\Infrastructure\Captures;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The review of a pending manual capture (§7.4, §10): approved or rejected
 * (with a reason) by a user other than its creator, or deleted with its
 * support by its creator. Only approved captures enter a calculation
 * (EloquentSettlementInputs).
 *
 * Each step is audited as comisiones.capture.<verb> with the capture kind
 * as subject type. The screen checks the period lock, the permissions,
 * ApprovalGuard::mayReview() and the creator before calling; the changes
 * here are conditional on "estado = pendiente", so of two reviewers only
 * one wins and a reviewed capture is never deleted.
 */
final class CaptureReviews
{
    public function __construct(
        private readonly Supports $supports,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * Approves a pending capture; false when it is no longer pending.
     */
    public function approve(CaptureKind $kind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture, User $reviewer, ?string $period): bool
    {
        return $this->review($kind, $capture, $reviewer, $period, CaptureStatus::Approved, null);
    }

    /**
     * Rejects a pending capture with its reason; false when it is no longer pending.
     */
    public function reject(CaptureKind $kind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture, User $reviewer, ?string $period, string $reason): bool
    {
        return $this->review($kind, $capture, $reviewer, $period, CaptureStatus::Rejected, $reason);
    }

    /**
     * Deletes a pending capture and its support; false when it is no longer pending.
     */
    public function delete(CaptureKind $kind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture, User $user, ?string $period): bool
    {
        $deleted = DB::transaction(function () use ($kind, $capture, $user, $period): bool {
            $rows = $capture->newQuery()
                ->whereKey($capture->getKey())
                ->where('estado', CaptureStatus::Pending->value)
                ->delete();

            if ($rows !== 1) {
                return false;
            }

            $this->audit->record(
                event: 'comisiones.capture.deleted',
                subjectType: $kind->value,
                subjectId: $capture->id,
                changes: self::snapshot($capture),
                period: $period,
                user: $user,
            );

            return true;
        });

        if ($deleted) {
            $this->supports->delete($capture->soporte);
        }

        return $deleted;
    }

    private function review(
        CaptureKind $kind,
        ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture,
        User $reviewer,
        ?string $period,
        CaptureStatus $status,
        ?string $reason,
    ): bool {
        return DB::transaction(function () use ($kind, $capture, $reviewer, $period, $status, $reason): bool {
            $rows = $capture->newQuery()
                ->whereKey($capture->getKey())
                ->where('estado', CaptureStatus::Pending->value)
                ->update([
                    'estado' => $status->value,
                    'revisado_por' => $reviewer->id,
                    'revisado_en' => CarbonImmutable::now(),
                    'motivo_rechazo' => $reason,
                ]);

            if ($rows !== 1) {
                return false;
            }

            $this->audit->record(
                event: $status === CaptureStatus::Approved ? 'comisiones.capture.approved' : 'comisiones.capture.rejected',
                subjectType: $kind->value,
                subjectId: $capture->id,
                changes: ['estado' => ['old' => CaptureStatus::Pending->value, 'new' => $status->value]],
                context: ['creado_por' => $capture->created_by],
                period: $period,
                user: $reviewer,
                reason: $reason,
            );

            return true;
        });
    }

    /**
     * The columns of a deleted capture worth keeping in the trail.
     *
     * @return array<string, mixed>
     */
    private static function snapshot(ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture): array
    {
        return array_diff_key($capture->attributesToArray(), array_flip([
            'id', 'estado', 'created_by', 'revisado_por', 'revisado_en', 'motivo_rechazo', 'created_at', 'updated_at',
        ]));
    }
}
