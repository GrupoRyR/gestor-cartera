<?php

namespace App\Modules\Core\Http\Controllers\Auth;

use App\Modules\Core\Application\Auth\IdentityProvider;
use App\Modules\Core\Application\Auth\SignInRejected;
use App\Modules\Core\Application\Auth\SignInWithMicrosoft;
use App\Modules\Core\Contracts\AuditLogger;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * GET /auth/microsoft/callback (core.auth.callback, guests only): where
 * Microsoft returns the guest. It must match MICROSOFT_REDIRECT_URI.
 *
 * A failure of Microsoft (invalid state, a failed request, an invalid id
 * token) is audited as core.auth.failed and the guest may try again; a
 * rejected account goes back to the sign-in page with its reason. A user who
 * signs in starts a new session and goes to the page they asked for, or home.
 *
 * An invalid state is not reported (audit S-09): it is an expired or replayed
 * callback, which anyone can send, and its trace would fill the log.
 */
final class MicrosoftCallbackController
{
    public function __invoke(
        Request $request,
        IdentityProvider $microsoft,
        SignInWithMicrosoft $signIn,
        AuditLogger $audit,
    ): RedirectResponse {
        try {
            $identity = $microsoft->user();
        } catch (Exception $exception) {
            if (! $exception instanceof InvalidStateException) {
                report($exception);
            }

            // The class only: Microsoft's message may echo the code or state.
            $audit->record('core.auth.failed', context: ['error' => class_basename($exception)]);

            return redirect()->route('core.login')->with('error', __('core::ingreso.error'));
        }

        try {
            $user = $signIn->handle($identity);
        } catch (SignInRejected $rejected) {
            return redirect()->route('core.login')->with('error', __($rejected->messageKey()));
        }

        Auth::login($user);
        $request->session()->regenerate();

        $audit->record('core.auth.signed_in', 'user', $user->id);

        return redirect()->intended(route('core.inicio'));
    }
}
