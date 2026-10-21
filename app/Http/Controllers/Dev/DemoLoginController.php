<?php

namespace App\Http\Controllers\Dev;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GET /dev/entrar (dev.entrar) and POST /dev/entrar/{user}
 * (dev.entrar.usuario): sign in as a demo user without Microsoft, only on a
 * developer's machine, to walk every screen with the demo seeders' data.
 *
 * Three locks keep it away from any real server: routes/web.php registers
 * the routes only in local; this controller answers 404 outside local (a
 * route cache built in local) and to any request whose socket address is not
 * the loopback (REMOTE_ADDR, never a forwarded header); and signing in is a
 * POST checked by the CSRF middleware of the web group.
 *
 * The page renders inline: it is a development tool, not a screen of the
 * platform, so it has no view of its own.
 */
final class DemoLoginController
{
    private const array LOOPBACK = ['127.0.0.1', '::1'];

    public function index(Request $request): Response
    {
        self::ensureLocalRequest($request);

        $users = User::query()
            ->where('is_active', true)
            ->whereHas('roles')
            ->with('roles')
            ->orderBy('name')
            ->get();

        $companies = self::companiesOf($users->modelKeys());

        $rows = $users->map(fn (User $user): array => [
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->map(self::roleLabel(...))->implode(', '),
            'companies' => implode(', ', $companies[$user->id] ?? []) ?: 'Sin empresas',
            'action' => route('dev.entrar.usuario', $user),
        ])->all();

        return response(Blade::render(self::TEMPLATE, ['users' => $rows]));
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        self::ensureLocalRequest($request);

        abort_unless($user->is_active && $user->roles()->exists(), 404);

        // A clean session: nothing of the previous demo user (active company,
        // intended URL) carries over to the next one.
        $request->session()->invalidate();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('core.inicio');
    }

    private static function ensureLocalRequest(Request $request): void
    {
        abort_unless(app()->isLocal(), 404);
        abort_unless(in_array($request->server('REMOTE_ADDR'), self::LOOPBACK, true), 404);
    }

    /**
     * The company codes of each user (company_user), in CompanyCode order.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<string>>
     */
    private static function companiesOf(array $userIds): array
    {
        $order = array_flip(array_map(fn (CompanyCode $code): string => $code->value, CompanyCode::cases()));
        $companies = [];

        foreach (DB::table('company_user')->whereIn('user_id', $userIds)->get(['user_id', 'company']) as $row) {
            $companies[(int) $row->user_id][] = (string) $row->company;
        }

        foreach ($companies as &$codes) {
            usort($codes, fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
        }

        return $companies;
    }

    /**
     * The label of a Role; a role of a module outside the enum (for example
     * comisiones_aprobador) reads as a sentence.
     */
    private static function roleLabel(string $name): string
    {
        return Role::tryFrom($name)?->label() ?? Str::ucfirst(str_replace('_', ' ', $name));
    }

    private const string TEMPLATE = <<<'BLADE'
        <x-layouts.guest title="Usuarios de prueba">
            <x-ui.page-header
                title="Entra como un usuario de prueba"
                description="Solo en tu equipo y en local: entras sin Microsoft para recorrer las pantallas con los datos de demostración."
            />

            @if ($users === [])
                <x-ui.empty
                    icon="users"
                    title="Todavía no hay usuarios de prueba"
                    description="Corre php artisan migrate --seed para crearlos con los datos de demostración."
                />
            @else
                <div class="grid gap-4">
                    @foreach ($users as $user)
                        <x-ui.card :title="$user['name']" :description="$user['email']">
                            <dl class="grid gap-2 text-sm">
                                <div>
                                    <dt class="text-fg-soft">Rol</dt>
                                    <dd>{{ $user['roles'] }}</dd>
                                </div>
                                <div>
                                    <dt class="text-fg-soft">Empresas</dt>
                                    <dd>{{ $user['companies'] }}</dd>
                                </div>
                            </dl>

                            <x-slot:footer>
                                <form method="POST" action="{{ $user['action'] }}">
                                    @csrf
                                    <x-ui.button type="submit" icon="user">Entrar como {{ $user['name'] }}</x-ui.button>
                                </form>
                            </x-slot:footer>
                        </x-ui.card>
                    @endforeach
                </div>
            @endif
        </x-layouts.guest>
        BLADE;
}
