<?php

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user deactivated from the Equipo screen on their next request
 * (Part B, Módulo 0N). CoreServiceProvider appends it to the "web" group, so
 * it guards every web page, with or without the auth middleware.
 *
 * Soft-deleted users never get here: the session guard does not resolve them
 * (SoftDeletes scope), so they are guests.
 */
final class EnsureUserIsActive
{
    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('web');
        $user = $guard->user();

        if (! $guard instanceof StatefulGuard || ! $user instanceof User || self::isActive($user)) {
            return $next($request);
        }

        $guard->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $message = __('core::acceso.cuenta_desactivada');

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_UNAUTHORIZED);
        }

        return redirect('/login')->with('error', $message);
    }

    /**
     * Reads the raw attribute: a user created in this request (or by a
     * factory), or loaded without the column, may lack is_active, and strict
     * models would throw instead of applying the column default (active).
     */
    private static function isActive(User $user): bool
    {
        return (bool) ($user->getAttributes()['is_active'] ?? true);
    }
}
