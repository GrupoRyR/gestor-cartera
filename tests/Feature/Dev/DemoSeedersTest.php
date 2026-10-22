<?php

use App\Models\User;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Database\Seeders\CoreDemoSeeder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| DatabaseSeeder runs the module demo seeders only in local. These tests seed
| the whole demo once and walk the main screens as each demo user, so a
| change that breaks the demo data (or a screen with it) shows up here.
*/
beforeEach(function () {
    $this->app['env'] = 'local';
});

/**
 * The period key $back months before the current one, in Bogotá.
 */
function demoPeriod(int $back): string
{
    return CarbonImmutable::today('America/Bogota')->startOfMonth()->subMonths($back)->format('Y-m');
}

function demoUser(string $slug): User
{
    return User::query()->where('email', CoreDemoSeeder::email($slug))->firstOrFail();
}

it('seeds a demo user per role with both companies', function () {
    $this->seed();

    $roles = [
        'admin' => ['admin'],
        'gerente-cartera' => ['gerente_cartera'],
        'analista-cartera' => ['analista_cartera'],
        'asesor' => ['asesor'],
        'gerente-comercial' => ['gerente_comercial'],
        'tesoreria' => ['tesoreria'],
        'aprobador-comisiones' => ['comisiones_aprobador', 'gerente_cartera'],
    ];

    foreach ($roles as $slug => $expected) {
        $user = demoUser($slug);
        $companies = DB::table('company_user')->where('user_id', $user->id)->orderBy('company')->pluck('company')->all();

        expect($user->is_active)->toBeTrue()
            ->and($user->getRoleNames()->sort()->values()->all())->toBe(collect($expected)->sort()->values()->all())
            ->and($companies)->toBe(['SDC', 'SGX']);
    }

    $asesor = demoUser('asesor');
    $manager = demoUser('gerente-comercial');

    expect(DB::table('advisors')->where('user_id', $asesor->id)->pluck('company')->sort()->values()->all())->toBe(['SDC', 'SGX'])
        ->and(DB::table('advisor_team')->where('user_id', $manager->id)->count())->toBe(6);
});

it('seeds a consistent portfolio in every status for both companies', function () {
    $this->seed();

    foreach (['SGX', 'SDC'] as $company) {
        $latest = DB::table('portfolio_loads')->where('company', $company)->orderByDesc('cutoff_date')->first();
        $statuses = DB::table('clients')->where('company', $company)->distinct()->pluck('status')->sort()->values()->all();

        expect(DB::table('portfolio_loads')->where('company', $company)->count())->toBe(12)
            ->and(substr($latest->cutoff_date, 0, 10))->toBe(CarbonImmutable::today('America/Bogota')->toDateString())
            ->and($statuses)->toBe(collect(ClientStatus::cases())->map->value->sort()->values()->all())
            ->and(DB::table('contacts')->join('clients', 'clients.id', '=', 'contacts.client_id')->where('clients.company', $company)->whereNull('contacts.email')->exists())->toBeTrue();

        foreach (DB::table('clients')->where('company', $company)->get() as $client) {
            $invoices = DB::table('invoices')->where('portfolio_load_id', $latest->id)->where('client_id', $client->id);

            expect(round((float) $client->balance, 2))->toBe(round((float) $invoices->sum('balance'), 2))
                ->and((int) $client->max_days_overdue)->toBe((int) ($invoices->max('days_overdue') ?? 0));
        }

        $commitments = DB::table('commitments')->where('company', $company)->distinct()->pluck('status')->sort()->values()->all();

        expect($commitments)->toBe(collect(CommitmentStatus::cases())->map->value->sort()->values()->all())
            ->and(DB::table('commitments')->where('company', $company)->where('created_by', demoUser('asesor')->id)->exists())->toBeTrue()
            ->and(DB::table('activities')->where('company', $company)->distinct()->count('type'))->toBeGreaterThan(4);
    }
});

it('seeds collection runs, commissions and loads, and does not repeat itself', function () {
    $this->seed();

    $counts = fn (): array => array_map(fn (string $table): int => DB::table($table)->count(), [
        'users', 'clients', 'invoices', 'commitments', 'activities', 'bancos_movimientos',
        'cobro_runs', 'cobro_run_items', 'cobro_inbound_messages', 'cobro_exclusiones',
        'comisiones_ventas', 'comisiones_recaudos', 'comisiones_liquidaciones', 'ingesta_loads',
    ]);
    $first = $counts();

    expect(DB::table('cobro_runs')->where('kind', 'cobro_semanal')->count())->toBe(3 * count(config('cobro.weekly.companies')))
        ->and(DB::table('cobro_runs')->where('kind', 'circularizacion')->distinct()->count('company'))->toBe(2)
        ->and(DB::table('cobro_run_items')->distinct()->count('status'))->toBe(6)
        ->and(DB::table('cobro_inbound_messages')->whereNull('read_at')->where('is_bounce', false)->exists())->toBeTrue()
        ->and(DB::table('cobro_inbound_messages')->whereNotNull('read_at')->exists())->toBeTrue()
        ->and(DB::table('cobro_inbound_messages')->where('is_bounce', true)->exists())->toBeTrue()
        ->and(DB::table('comisiones_liquidaciones')->orderBy('periodo')->pluck('estado', 'periodo')->all())->toBe([
            demoPeriod(3) => 'cerrada',
            demoPeriod(2) => 'aprobada',
            demoPeriod(1) => 'borrador',
        ])
        ->and(DB::table('comisiones_ventas')->where('fecha_documento', '>=', demoPeriod(0).'-01')->exists())->toBeTrue()
        ->and(DB::table('comisiones_obr')->where('estado', 'pendiente')->exists())->toBeTrue()
        ->and(DB::table('comisiones_reasignaciones')->where('estado', 'pendiente')->exists())->toBeTrue()
        ->and(DB::table('ingesta_loads')->where('status', 'rechazada')->count())->toBe(2)
        ->and(DB::table('ingesta_loads')->distinct()->count('company'))->toBe(2);

    $this->seed();

    expect($counts())->toBe($first);
});

it('opens the main screens of every demo user', function () {
    $this->seed();

    $client = DB::table('clients')->where('company', 'SGX')->orderBy('id')->value('id');
    $advisorCode = DB::table('advisors')->where('user_id', demoUser('asesor')->id)->where('company', 'SGX')->value('code');
    $ownClient = DB::table('clients')->where('company', 'SGX')->where('advisor_code', $advisorCode)->value('id');
    $run = DB::table('cobro_runs')->value('id');
    $reply = DB::table('cobro_inbound_messages')->value('id');
    $rejected = DB::table('ingesta_loads')->where('status', 'rechazada')->value('id');

    $screens = [
        'admin' => [
            ['core.equipo.index'], ['core.empresas.index'], ['cobro.lotes.index'], ['ingesta.index'],
            ['comisiones.parametros.index'], ['comisiones.auditoria.index'],
        ],
        'gerente-cartera' => [
            ['cartera.tablero'], ['cartera.clientes.index'], ['cartera.clientes.show', $client], ['cartera.kanban'],
            ['cartera.compromisos.index'], ['gerencia.tablero'], ['bancos.recaudo'], ['cobro.semanal.show'],
            ['cobro.circularizacion.show'], ['cobro.respuestas.index'], ['cobro.respuestas.show', $reply],
            ['cobro.lotes.index'], ['cobro.lotes.show', $run], ['cobro.exclusiones.index'], ['ingesta.index'],
            ['ingesta.cargas.show', $rejected], ['comisiones.tablero'], ['comisiones.periodos.show', demoPeriod(1)],
            ['comisiones.periodos.consolidado', demoPeriod(2)], ['comisiones.periodos.controles', demoPeriod(1)],
            ['comisiones.periodos.informes.index', demoPeriod(3)], ['comisiones.ajustes.index'],
            ['comisiones.presupuesto.index'], ['core.empresas.index'],
        ],
        'analista-cartera' => [
            ['cartera.tablero'], ['cartera.kanban'], ['cartera.compromisos.index'], ['cobro.respuestas.index'],
            ['cobro.lotes.index'], ['bancos.recaudo'], ['ingesta.index'],
        ],
        'asesor' => [
            ['asesores.inicio'], ['asesores.clientes.index'], ['asesores.clientes.show', $ownClient],
            ['asesores.agenda'], ['asesores.historico'],
        ],
        'gerente-comercial' => [['asesores.inicio'], ['asesores.equipo.index'], ['asesores.agenda']],
        'tesoreria' => [['bancos.recaudo'], ['bancos.cargas.index']],
        'aprobador-comisiones' => [
            ['comisiones.tablero'], ['comisiones.periodos.show', demoPeriod(1)], ['comisiones.ajustes.index'],
        ],
    ];

    foreach ($screens as $slug => $routes) {
        $user = demoUser($slug);
        $this->flushSession();

        $home = $this->actingAs($user)->get(route('core.inicio'));

        expect($home->isRedirect())->toBeTrue("{$slug}: core.inicio does not redirect")
            ->and($home->headers->get('Location'))->not->toBe(route('core.sin-acceso'));

        foreach ($routes as $route) {
            [$name, $parameter] = $route + [1 => null];
            $response = $this->actingAs($user)->get(route($name, $parameter ?? []));

            expect($response->getStatusCode())->toBe(200, "{$slug}: {$name} answered {$response->getStatusCode()}");
        }
    }
});
