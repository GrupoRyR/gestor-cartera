<?php

namespace App\Modules\Core\Http\Controllers\Team;

use App\Models\User;
use App\Modules\Core\Application\Team\TeamChangeRefused;
use App\Modules\Core\Application\Team\TeamDirectory;
use App\Modules\Core\Application\Team\TeamFilter;
use App\Modules\Core\Application\Team\TeamMembers;
use App\Modules\Core\Application\Team\TeamTab;
use App\Modules\Core\Http\Requests\Team\StoreTeamMemberRequest;
use App\Modules\Core\Http\Requests\Team\UpdateTeamMemberRequest;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Equipo screen (core.equipo.*), for admins only (core.team.manage):
 * list, pre-register, edit, soft-delete and restore users. TeamMembers does
 * the writes, the audit and the lock-out guards; a refused change goes back
 * with the "error" flash.
 */
final class TeamMemberController
{
    public function __construct(private readonly TeamMembers $members) {}

    /**
     * GET /equipo: one tab per state, searched, sorted and paginated.
     */
    public function index(Request $request, TeamDirectory $directory): View
    {
        $filter = TeamFilter::fromQuery($request->query());
        $members = $directory->page($filter);

        return view('core::equipo.index', [
            'filter' => $filter,
            'members' => $members,
            'companies' => $directory->companiesOf($members->items()),
            'counts' => $directory->counts($filter->search),
        ]);
    }

    /**
     * GET /equipo/nuevo.
     */
    public function create(): View
    {
        return view('core::equipo.nuevo');
    }

    /**
     * POST /equipo.
     */
    public function store(StoreTeamMemberRequest $request): RedirectResponse
    {
        try {
            $user = $this->members->create($request->email(), $request->details());
        } catch (TeamChangeRefused $refused) {
            return back()->withInput()->with('error', __($refused->messageKey()));
        }

        $message = $user->wasRecentlyCreated ? 'core::equipo.mensajes.creado' : 'core::equipo.mensajes.restaurado_al_crear';

        return redirect()->route('core.equipo.index')->with('status', __($message, ['nombre' => $user->name]));
    }

    /**
     * GET /equipo/{user}/editar. Deleted users are not found here.
     */
    public function edit(Request $request, User $user, TeamDirectory $directory): View
    {
        return view('core::equipo.editar', [
            'member' => $user->load('roles'),
            'companies' => $directory->companiesOf([$user])[$user->id] ?? [],
            'isSelf' => $user->is($request->user()),
        ]);
    }

    /**
     * PUT /equipo/{user}.
     */
    public function update(UpdateTeamMemberRequest $request, User $user): RedirectResponse
    {
        try {
            $this->members->update($this->actor($request), $user, $request->details());
        } catch (TeamChangeRefused $refused) {
            return back()->withInput()->with('error', __($refused->messageKey()));
        }

        return redirect()->route('core.equipo.index')->with('status', __('core::equipo.mensajes.actualizado', ['nombre' => $user->name]));
    }

    /**
     * DELETE /equipo/{user}: soft delete.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        try {
            $this->members->delete($this->actor($request), $user);
        } catch (TeamChangeRefused $refused) {
            return back()->with('error', __($refused->messageKey()));
        }

        return redirect()->route('core.equipo.index')->with('status', __('core::equipo.mensajes.eliminado', ['nombre' => $user->name]));
    }

    /**
     * POST /equipo/{user}/restaurar. The route also finds deleted users.
     */
    public function restore(User $user): RedirectResponse
    {
        $message = $this->members->restore($user) ? 'core::equipo.mensajes.restaurado' : 'core::equipo.mensajes.no_eliminado';

        return redirect()
            ->route('core.equipo.index', ['estado' => TeamTab::Deleted->value])
            ->with('status', __($message, ['nombre' => $user->name]));
    }

    /**
     * The signed-in admin. The routes require a session, so a guest never
     * gets this far.
     */
    private function actor(Request $request): User
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            throw new AuthenticationException;
        }

        return $actor;
    }
}
