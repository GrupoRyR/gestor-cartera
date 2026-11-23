<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Infrastructure\Notices\EloquentAvisoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The notices of the signed-in user (docs/arquitectura.md §6.18), through
 * Core's inbox: the bell of the layout and its page. Every action takes the
 * user's id, so nobody reads or marks another user's notices.
 *
 * - GET /avisos (core.avisos.index): 20 per page, newest first; the tab
 *   «No leídos» (?filtro=no-leidos) lists only the unread ones.
 * - GET /avisos/{notice} (core.avisos.open): marks it read and follows the
 *   link of its kind, or comes back to the list; 404 if it is not the user's.
 * - POST /avisos/leer-todos (core.avisos.read-all): marks all as read.
 */
final class NoticeController
{
    public const string UNREAD_FILTER = 'no-leidos';

    public function index(Request $request, EloquentAvisoService $inbox): View
    {
        $userId = self::userId($request);
        $filter = $request->query('filtro') === self::UNREAD_FILTER ? self::UNREAD_FILTER : 'todos';

        return view('core::avisos.index', [
            'notices' => $inbox->forUser($userId, unreadOnly: $filter === self::UNREAD_FILTER),
            'filter' => $filter,
            'unread' => $inbox->unreadCount($userId),
        ]);
    }

    public function open(Request $request, int $notice, EloquentAvisoService $inbox): RedirectResponse
    {
        $opened = $inbox->markRead(self::userId($request), $notice);

        if ($opened === null) {
            abort(404);
        }

        return redirect()->to($inbox->link($opened) ?? route('core.avisos.index'));
    }

    public function readAll(Request $request, EloquentAvisoService $inbox): RedirectResponse
    {
        $inbox->markAllRead(self::userId($request));

        return back()->with('status', __('core::avisos.marcados'));
    }

    /**
     * The routes run behind "auth", so there is always a user.
     */
    private static function userId(Request $request): int
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        return (int) $user->getAuthIdentifier();
    }
}
