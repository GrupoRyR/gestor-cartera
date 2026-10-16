<?php

namespace App\Modules\Core\Http\Controllers;

use App\Support\Navigation\NavRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * GET / (core.inicio): the home by role (Part A.6). Core does not know the
 * other modules' routes: the user lands on the first menu entry they may
 * open, so each module orders its main screen first for the roles that use
 * it. A user without a role goes to the no-access screen.
 */
final class HomeController
{
    public function __invoke(Request $request, NavRegistry $navigation): RedirectResponse|View
    {
        $user = $request->user();

        if ($user === null || $user->cannot('core.access')) {
            return redirect()->route('core.sin-acceso');
        }

        foreach ($navigation->visibleTo($user) as $item) {
            // An entry that points back here would redirect forever.
            if ($item->route !== 'core.inicio') {
                return redirect()->to($item->url());
            }
        }

        return view('core::inicio', [
            'firstName' => Str::before(trim($user->name), ' '),
        ]);
    }
}
