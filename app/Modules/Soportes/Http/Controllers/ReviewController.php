<?php

namespace App\Modules\Soportes\Http\Controllers;

use App\Models\User;
use App\Modules\Soportes\Application\Review\ReviewReceipt;
use App\Modules\Soportes\Domain\Exceptions\InvalidReceiptTransition;
use App\Modules\Soportes\Http\Requests\ApplyReceiptRequest;
use App\Modules\Soportes\Http\Requests\RejectReceiptRequest;
use App\Modules\Soportes\Http\Requests\ReviewReceiptRequest;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /soportes/{soporte}/aplicar (soportes.apply) and
 * PATCH /soportes/{soporte}/rechazar (soportes.reject): the reviewer's
 * decision, back on the receipt's detail.
 *
 * A receipt another reviewer already decided (before this request read it,
 * or while it ran) goes back with a message instead of 403, so the late
 * reviewer knows what happened; nothing changes.
 */
final readonly class ReviewController
{
    public function __construct(private ReviewReceipt $review) {}

    public function apply(ApplyReceiptRequest $request, Receipt $soporte): RedirectResponse
    {
        return $this->decide($request, $soporte, 'aplicado', fn (User $user) => $this->review->apply($soporte, $user, $request->receiptNumber()));
    }

    public function reject(RejectReceiptRequest $request, Receipt $soporte): RedirectResponse
    {
        return $this->decide($request, $soporte, 'rechazado', fn (User $user) => $this->review->reject($soporte, $user, $request->reason()));
    }

    /**
     * @param  Closure(User): void  $decision
     */
    private function decide(ReviewReceiptRequest $request, Receipt $receipt, string $result, Closure $decision): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $back = redirect()->route('soportes.show', $receipt);

        if ($receipt->status->isFinal()) {
            return $back->with('error', __('soportes::revision.ya_revisado'));
        }

        Gate::forUser($user)->authorize('review', $receipt);

        try {
            $decision($user);
        } catch (InvalidReceiptTransition) {
            return $back->with('error', __('soportes::revision.ya_revisado'));
        }

        return $back->with('status', __('soportes::revision.resultado.'.$result));
    }
}
