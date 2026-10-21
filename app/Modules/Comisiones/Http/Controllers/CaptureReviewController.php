<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Domain\Shared\Period;
use App\Modules\Comisiones\Http\Requests\RejectCaptureRequest;
use App\Modules\Comisiones\Infrastructure\Captures\CaptureKind;
use App\Modules\Comisiones\Infrastructure\Captures\CaptureReviews;
use App\Modules\Comisiones\Infrastructure\Captures\PeriodLock;
use App\Modules\Comisiones\Infrastructure\Persistence\AdjustmentRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\CaptureStatus;
use App\Modules\Comisiones\Infrastructure\Persistence\CrossingRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ReassignmentRecord;
use App\Modules\Comisiones\Infrastructure\Settlement\ApprovalGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The review of a manual capture (§7.4, §10) under
 * /comisiones/ajustes/{tipo}/{captura}:
 *
 * - aprobar and rechazar (comisiones.approve): only while pending, never by
 *   its creator (ApprovalGuard::mayReview()); a rejection needs a reason;
 * - destroy (comisiones.capture): only while pending, only by its creator.
 *
 * A capture of a closed period (PeriodLock) is frozen. Each step answers
 * on the capture's tab with a Spanish message.
 */
final class CaptureReviewController
{
    public function __construct(
        private readonly CaptureReviews $reviews,
        private readonly ApprovalGuard $guard,
        private readonly PeriodLock $lock,
    ) {}

    public function approve(Request $request, string $tipo, string $captura): RedirectResponse
    {
        return $this->step($request, $tipo, $captura, function (CaptureKind $kind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture, User $user, ?Period $period): ?string {
            if (! $this->guard->mayReview($capture, $user)) {
                return __('comisiones::ajustes.mensajes.propio');
            }

            return $this->reviews->approve($kind, $capture, $user, $period?->key()) ? null : __('comisiones::ajustes.mensajes.ya_revisado');
        }, 'aprobado');
    }

    public function reject(RejectCaptureRequest $request, string $tipo, string $captura): RedirectResponse
    {
        $reason = $request->reason();

        return $this->step($request, $tipo, $captura, function (CaptureKind $kind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture, User $user, ?Period $period) use ($reason): ?string {
            if (! $this->guard->mayReview($capture, $user)) {
                return __('comisiones::ajustes.mensajes.propio');
            }

            return $this->reviews->reject($kind, $capture, $user, $period?->key(), $reason) ? null : __('comisiones::ajustes.mensajes.ya_revisado');
        }, 'rechazado');
    }

    public function destroy(Request $request, string $tipo, string $captura): RedirectResponse
    {
        return $this->step($request, $tipo, $captura, function (CaptureKind $kind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord $capture, User $user, ?Period $period): ?string {
            if ($capture->created_by !== $user->id) {
                return __('comisiones::ajustes.mensajes.solo_creador');
            }

            return $this->reviews->delete($kind, $capture, $user, $period?->key()) ? null : __('comisiones::ajustes.mensajes.ya_revisado');
        }, 'eliminado');
    }

    /**
     * Finds the capture, refuses a closed period or a reviewed capture, and
     * runs the step, which returns null when done or the refusal message.
     *
     * @param  Closure(CaptureKind, ObrRecord|AdjustmentRecord|CrossingRecord|ReassignmentRecord, User, ?Period): ?string  $step
     */
    private function step(Request $request, string $tipo, string $captura, Closure $step, string $done): RedirectResponse
    {
        $kind = CaptureKind::from($tipo);
        $capture = $kind->find((int) $captura);
        abort_if($capture === null, 404);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $period = $kind->periodOf($capture);
        $back = redirect()->route('comisiones.ajustes.index', array_filter(['pestana' => $kind->tab(), 'periodo' => $period?->key()]));

        if ($period !== null && $this->lock->isLocked($period)) {
            return $back->with('error', PeriodLock::message());
        }

        if ($capture->estado !== CaptureStatus::Pending) {
            return $back->with('error', __('comisiones::ajustes.mensajes.ya_revisado'));
        }

        $refused = $step($kind, $capture, $user, $period);

        if ($refused !== null) {
            return $back->with('error', $refused);
        }

        return $back->with('status', __("comisiones::ajustes.mensajes.{$done}", [
            'que' => __('comisiones::ajustes.mensajes.que.'.$kind->value),
            'periodo' => $period?->key() ?? '',
        ]));
    }
}
