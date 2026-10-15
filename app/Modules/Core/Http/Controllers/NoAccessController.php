<?php

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /sin-acceso (core.sin-acceso): the neutral screen of a user whose
 * Microsoft account is registered but has no role yet (Part A.5). A user who
 * already has access goes home.
 */
final class NoAccessController
{
    public function __invoke(Request $request): RedirectResponse|View
    {
        $user = $request->user();

        if ($user !== null && $user->can('core.access')) {
            return redirect()->route('core.inicio');
        }

        return view('core::sin-acceso', ['email' => $user?->email]);
    }
}
