@use('App\Models\User')
@use('App\Modules\Core\Contracts\CurrentCompany')
@use('App\Modules\Core\Contracts\Enums\ClientStatus')
@use('App\Modules\Core\Contracts\Enums\CompanyCode')
@use('App\Support\Navigation\NavItem')
@use('App\Support\Navigation\NavRegistry')
@use('App\View\Components\Support\GalleryPreview')
@use('App\View\Components\Support\IconSet')
@use('Carbon\CarbonImmutable')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('Illuminate\Pagination\Paginator')
@use('Illuminate\Support\MessageBag')
@use('Illuminate\Support\ViewErrorBag')
@use('Tests\Fakes\FakeCurrentCompany')

{{--
    Component gallery (route dev.componentes, local only: any other
    environment answers 404 before this view renders). One section per
    component of the C.5 contract and its additions, with every state and
    fictitious sample data.

    The page previews the signed-in chrome: ?empresa=SGX|SDC|grupo picks the
    brand through the CurrentCompany fake (tests/Fakes, autoloaded in local
    only), a request-scoped sample user fills the account menu, and sample
    menu entries appear while no module has registered any. Nothing is
    stored: the user is never logged in and the session is not touched.
    ?vista=ingreso shows the guest layout with a sample sign-in.
--}}

@php
    $brandParam = is_string(request()->query('empresa')) ? request()->query('empresa') : 'SGX';
    $activeCode = match ($brandParam) {
        'SDC' => CompanyCode::SDC,
        'grupo' => null,
        default => CompanyCode::SGX,
    };
    $brandKey = $activeCode?->value ?? 'grupo';

    app()->instance(CurrentCompany::class, new FakeCurrentCompany($activeCode));
    app()->instance(GalleryPreview::class, new GalleryPreview(route('dev.componentes')));

    if (auth()->guest()) {
        auth()->setUser(new User(['name' => 'María Paula Rojas', 'email' => 'maria.rojas@segurex.example']));
    }

    $navigation = app(NavRegistry::class);

    if ($navigation->menuFor(auth()->user())['groups'] === [] && ! $navigation->has('galeria.tablero')) {
        // Every sample entry opens the gallery; only "Tablero" shows as the current page.
        $sample = fn (string $key, string $label, string $icon, string $group, int $order, bool $mobile = false, ?int $badge = null) => new NavItem(
            key: "galeria.{$key}",
            label: $label,
            route: 'dev.componentes',
            icon: $icon,
            group: $group,
            order: $order,
            activePattern: $key === 'tablero' ? 'dev.componentes' : 'galeria.ninguna',
            mobile: $mobile,
            badge: $badge === null ? null : fn (): int => $badge,
        );

        $navigation->add(
            $sample('tablero', 'Tablero', 'dashboard', 'Cartera', 10, mobile: true),
            $sample('kanban', 'Kanban', 'board', 'Cartera', 20),
            $sample('clientes', 'Clientes', 'users', 'Cartera', 30, mobile: true),
            $sample('compromisos', 'Compromisos', 'calendar-check', 'Cartera', 40, mobile: true, badge: 12),
            $sample('respuestas', 'Respuestas de clientes', 'inbox', 'Cartera', 50, mobile: true, badge: 4),
            $sample('cobro', 'Cobro semanal', 'send', 'Cobro', 60),
            $sample('exclusiones', 'Exclusiones', 'filter', 'Cobro', 70),
            $sample('movimientos', 'Movimientos de bancos', 'bank', 'Bancos', 80),
            $sample('recaudo', 'Recaudo diario', 'money', 'Bancos', 90),
            $sample('gerencia', 'Tablero gerencial', 'chart', 'Gerencia', 100),
            $sample('equipo', 'Equipo', 'user', 'Administración', 110),
        );
    }

    // Sample validation errors, so the form fields also show their invalid state.
    view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
        'galeria_correo' => 'Escribe un correo válido, por ejemplo pagos@cliente.com.',
        'galeria_estado' => 'Elige un estado.',
        'galeria_aviso' => 'Confirma que el cliente autorizó el envío.',
        'galeria_nota' => 'Escribe qué acordaste con el cliente.',
    ])));

    $gallerySections = [
        'layouts-app' => 'x-layouts.app',
        'layouts-guest' => 'x-layouts.guest',
        'ui-page-header' => 'x-ui.page-header',
        'ui-card' => 'x-ui.card',
        'ui-stat' => 'x-ui.stat',
        'ui-chip' => 'x-ui.chip',
        'ui-button' => 'x-ui.button',
        'ui-link-button' => 'x-ui.link-button',
        'ui-table' => 'x-ui.table',
        'ui-empty' => 'x-ui.empty',
        'ui-alert' => 'x-ui.alert',
        'ui-input' => 'x-ui.input',
        'ui-select' => 'x-ui.select',
        'ui-checkbox' => 'x-ui.checkbox',
        'ui-textarea' => 'x-ui.textarea',
        'ui-modal' => 'x-ui.modal',
        'ui-tabs' => 'x-ui.tabs',
        'ui-badge-state' => 'x-ui.badge-state',
        'ui-money' => 'x-ui.money',
        'ui-date' => 'x-ui.date',
        'ui-icon' => 'x-ui.icon',
        'ui-pagination' => 'x-ui.pagination',
    ];

    $brandTabs = [
        ['key' => 'SGX', 'label' => 'Segurex Latam', 'href' => request()->fullUrlWithQuery(['empresa' => 'SGX'])],
        ['key' => 'SDC', 'label' => 'Soluciones y Derivados', 'href' => request()->fullUrlWithQuery(['empresa' => 'SDC'])],
        ['key' => 'grupo', 'label' => 'Grupo RYR (neutra)', 'href' => request()->fullUrlWithQuery(['empresa' => 'grupo'])],
    ];

    $sortBy = in_array(request()->query('sort'), ['name', 'balance', 'overdue', 'days_overdue'], true) ? request()->query('sort') : 'overdue';
    $sortDirection = request()->query('direction') === 'asc' ? 'asc' : 'desc';

    $columns = [
        ['key' => 'name', 'label' => 'Cliente', 'sortable' => true],
        ['key' => 'tax_id', 'label' => 'NIT'],
        ['key' => 'balance', 'label' => 'Saldo', 'sortable' => true, 'align' => 'end'],
        ['key' => 'overdue', 'label' => 'Saldo vencido', 'sortable' => true, 'align' => 'end'],
        ['key' => 'days_overdue', 'label' => 'Días de mora', 'sortable' => true, 'align' => 'end'],
        ['key' => 'status', 'label' => 'Estado'],
        ['key' => 'last_activity', 'label' => 'Última gestión'],
    ];

    $clients = [
        ['name' => 'Ferretería El Tornillo SAS', 'tax_id' => '900.123.456-7', 'balance' => 48250000, 'overdue' => 12500000, 'days' => 45, 'status' => ClientStatus::Overdue, 'last' => '2026-10-09 10:15'],
        ['name' => 'Distribuciones Andina Ltda.', 'tax_id' => '830.045.112-3', 'balance' => 21400000, 'overdue' => 3200000, 'days' => 12, 'status' => ClientStatus::Committed, 'last' => '2026-10-14 16:40'],
        ['name' => 'Construcciones del Valle SAS', 'tax_id' => '901.778.230-1', 'balance' => 9800000, 'overdue' => 0, 'days' => 0, 'status' => ClientStatus::Current, 'last' => null],
        ['name' => 'Montajes Eléctricos del Caribe SAS', 'tax_id' => '900.556.871-0', 'balance' => 15600000, 'overdue' => 15600000, 'days' => 97, 'status' => ClientStatus::Defaulted, 'last' => '2026-09-30 09:05'],
    ];

    $statusTabs = [
        ['key' => 'todos', 'label' => 'Todos', 'href' => request()->fullUrlWithQuery(['pestana' => 'todos']), 'count' => 128],
        ['key' => 'vencidos', 'label' => 'Vencidos', 'href' => request()->fullUrlWithQuery(['pestana' => 'vencidos']), 'count' => 42],
        ['key' => 'compromisos', 'label' => 'Con compromiso', 'href' => request()->fullUrlWithQuery(['pestana' => 'compromisos']), 'count' => 9],
        ['key' => 'juridico', 'label' => 'Jurídico', 'href' => request()->fullUrlWithQuery(['pestana' => 'juridico'])],
    ];
    $currentTab = in_array(request()->query('pestana'), ['todos', 'vencidos', 'compromisos', 'juridico'], true) ? request()->query('pestana') : 'vencidos';

    $pages = fn (int $current) => new LengthAwarePaginator(range(1, 25), total: 70, perPage: 25, currentPage: $current, options: ['path' => request()->url(), 'pageName' => "pagina_{$current}"]);
    $simplePages = new Paginator(range(1, 26), perPage: 25, currentPage: 1, options: ['path' => request()->url(), 'pageName' => 'pagina_simple']);

    $sampleDate = CarbonImmutable::parse('2026-10-15 14:30', config('app.timezone'));
@endphp

@if (request()->query('vista') === 'ingreso')
    {{-- Sample of the sign-in page Core builds (A.5): neutral, no company logo, one button. --}}
    <x-layouts.guest title="Ingresar">
        <x-ui.page-header title="Ingresa a tu cartera" context="Vista previa de la galería" />

        <p>Usa tu cuenta corporativa de Microsoft. Verás la marca y los clientes de la empresa en la que trabajas.</p>

        <div class="flex flex-col gap-3">
            <x-ui.link-button href="#" icon="user" class="w-full">Entrar con Microsoft</x-ui.link-button>
            <x-ui.link-button :href="request()->fullUrlWithoutQuery(['vista'])" variant="ghost" icon="chevron-left" class="w-full">Volver a la galería</x-ui.link-button>
        </div>
    </x-layouts.guest>
@else
    <x-layouts.app title="Componentes">
        {{-- The page heading is also the <x-ui.page-header> sample: a page has only one <h1>. --}}
        <x-ui.page-header
            title="Componentes"
            context="Solo en local"
            description="Cada componente del contrato C.5 con todos sus estados y datos de ejemplo. Los módulos usan estos componentes tal como están: no crean estilos propios."
        >
            <x-slot:actions>
                <x-ui.button icon="download" variant="secondary">Exportar</x-ui.button>
                <x-ui.link-button href="#component-ui-modal" icon="plus">Nuevo compromiso</x-ui.link-button>
            </x-slot:actions>
        </x-ui.page-header>

        <x-ui.card title="Vista previa" description="La marca sale de la empresa activa. Cambia de empresa aquí o con el selector del encabezado." class="mb-8">
            <x-ui.tabs label="Marca de la vista previa" :items="$brandTabs" :current="$brandKey" />
            <ul class="mt-4 grid gap-2 text-sm md:grid-cols-3">
                <li class="flex gap-2"><x-ui.icon name="eye" class="size-[1.125rem] text-fg-muted" />El modo oscuro sigue la configuración de tu sistema.</li>
                <li class="flex gap-2"><x-ui.icon name="search" class="size-[1.125rem] text-fg-muted" />Para probar la letra al 200 %, súbela en tu navegador: todo está en rem.</li>
                <li class="flex gap-2"><x-ui.icon name="user" class="size-[1.125rem] text-fg-muted" /><a href="{{ request()->fullUrlWithQuery(['vista' => 'ingreso']) }}" class="font-semibold text-brand-ink underline-offset-4 hover:underline">Ver la pantalla de ingreso</a></li>
            </ul>
        </x-ui.card>

        <nav aria-label="Índice de componentes" class="mb-4">
            <ul class="flex flex-wrap gap-2">
                @foreach ($gallerySections as $slug => $tag)
                    <li><a href="#component-{{ $slug }}" class="inline-flex min-h-9 items-center rounded-lg border border-line bg-surface px-2.5 font-mono text-[0.8125rem] text-brand-text no-underline hover:border-line-strong hover:text-fg">&lt;{{ $tag }}&gt;</a></li>
                @endforeach
            </ul>
        </nav>

        @php
            $sectionClass = 'scroll-mt-24 border-t border-line pt-8 mt-10 flex flex-col gap-5';
            $headingClass = 'font-mono text-[1.0625rem] font-semibold text-fg';
            $leadClass = 'max-w-prose text-[0.9375rem]';
            $specimenClass = 'flex flex-col gap-2';
            $captionClass = 'text-[0.8125rem] font-semibold text-fg-muted';
        @endphp

        <section id="component-layouts-app" aria-labelledby="component-layouts-app-title" class="{{ $sectionClass }}">
            <h2 id="component-layouts-app-title" class="{{ $headingClass }}"><code>&lt;x-layouts.app&gt;</code></h2>
            <p class="{{ $leadClass }}">Esta misma página usa el layout de la aplicación: encabezado oscuro con el logo de la empresa activa, los botones para cambiar de empresa y tu cuenta, barra lateral en escritorio (un menú lateral por debajo de 1024 px) y barra inferior en el celular con las entradas marcadas <code>mobile</code>. Los avisos de la sesión aparecen arriba del contenido.</p>
            <x-ui.alert tone="success">Así se ve <code>session('status')</code> después de guardar.</x-ui.alert>
        </section>

        <section id="component-layouts-guest" aria-labelledby="component-layouts-guest-title" class="{{ $sectionClass }}">
            <h2 id="component-layouts-guest-title" class="{{ $headingClass }}"><code>&lt;x-layouts.guest&gt;</code></h2>
            <p class="{{ $leadClass }}">El ingreso y las páginas de error usan el layout sin sesión: siempre con la paleta neutra y sin navegación. Como nadie tiene empresa antes de ingresar, el encabezado muestra el nombre de la app y ningún logo: la marca nunca junta las dos empresas.</p>
            <div class="flex flex-wrap gap-2">
                <x-ui.link-button :href="request()->fullUrlWithQuery(['vista' => 'ingreso'])" variant="secondary" icon="user">Ver la pantalla de ingreso</x-ui.link-button>
                <x-ui.link-button :href="url('/dev/componentes/pagina-que-no-existe')" variant="secondary">Ver la página 404</x-ui.link-button>
            </div>
        </section>

        <section id="component-ui-page-header" aria-labelledby="component-ui-page-header-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-page-header-title" class="{{ $headingClass }}"><code>&lt;x-ui.page-header&gt;</code></h2>
            <p class="{{ $leadClass }}">El encabezado de esta página es el ejemplo: contexto, título, descripción y acciones. Cada página tiene un solo <code>&lt;h1&gt;</code>; en el celular las acciones bajan y ocupan todo el ancho.</p>
        </section>

        <section id="component-ui-card" aria-labelledby="component-ui-card-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-card-title" class="{{ $headingClass }}"><code>&lt;x-ui.card&gt;</code></h2>
            <p class="{{ $leadClass }}">Bloque de contenido. Con título es una sección con nombre; el nivel del encabezado (2, 3 o 4) sigue la jerarquía de la página.</p>
            <div class="grid items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                <x-ui.card title="Recaudo de la semana" description="Del lunes 12 al domingo 18 de octubre." :level="3">
                    <x-slot:actions>
                        <x-ui.button variant="ghost" icon="refresh" :icon-only="true">Actualizar</x-ui.button>
                    </x-slot:actions>
                    <p>Llegaron 38 pagos por <x-ui.money :value="184500000" class="font-semibold" />.</p>
                    <x-slot:footer>Fuente: movimientos de bancos.</x-slot:footer>
                </x-ui.card>
                <x-ui.card title="Compromisos de hoy" :level="3">
                    <x-slot:actions>
                        <x-ui.link-button href="#component-ui-card" variant="ghost">Ver todos</x-ui.link-button>
                    </x-slot:actions>
                    <ul class="divide-y divide-line">
                        <li class="flex items-center justify-between gap-3 py-2"><span>Distribuciones Andina Ltda.</span><x-ui.money :value="3200000" class="font-semibold" /></li>
                        <li class="flex items-center justify-between gap-3 py-2"><span>Ferretería El Tornillo SAS</span><x-ui.money :value="2500000" class="font-semibold" /></li>
                    </ul>
                </x-ui.card>
                <x-ui.card>
                    <p>Bloque sin título: no se anuncia como sección y sirve para agrupar contenido suelto.</p>
                </x-ui.card>
            </div>
        </section>

        <section id="component-ui-stat" aria-labelledby="component-ui-stat-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-stat-title" class="{{ $headingClass }}"><code>&lt;x-ui.stat&gt;</code></h2>
            <p class="{{ $leadClass }}">Indicador. El tono (punto y señal) y la tendencia (flecha) son independientes: que el saldo vencido baje es una buena noticia. La cifra va en el color del texto y se achica para caber en su tarjeta.</p>
            <p class="{{ $leadClass }}">Rejilla recomendada: <code>grid-cols-1 min-[22rem]:grid-cols-2 lg:grid-cols-4</code>. Como el corte está en rem, un celular muestra dos columnas y pasa a una sola cuando la persona agranda la letra.</p>
            <div class="grid grid-cols-1 gap-3 min-[22rem]:grid-cols-2 md:gap-4 lg:grid-cols-4">
                <x-ui.stat label="Saldo vencido" trend="down" trend-label="Baja 8 % frente al mes pasado" tone="success" hint="8 % menos que en septiembre">
                    <x-ui.money :value="1250000000" />
                </x-ui.stat>
                <x-ui.stat label="Clientes vencidos" value="42" trend="up" hint="5 más que la semana pasada" tone="warning" />
                <x-ui.stat label="Compromisos incumplidos" value="7" trend="flat" tone="danger" href="#component-ui-stat" hint="Toca para ver la lista" />
                <x-ui.stat label="Correos enviados hoy" value="120" />
                <x-ui.stat label="Cartera total">
                    <x-ui.money :value="4875300000" />
                </x-ui.stat>
                <x-ui.stat label="% vencido" value="25,6 %" trend="up" tone="danger" />
                <x-ui.stat label="Recaudo de ayer" hint="Aún no llegan los movimientos" />
                <x-ui.stat label="Respuestas sin leer" value="4" tone="warning" href="#component-ui-stat" />
            </div>
        </section>

        <section id="component-ui-chip" aria-labelledby="component-ui-chip-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-chip-title" class="{{ $headingClass }}"><code>&lt;x-ui.chip&gt;</code></h2>
            <p class="{{ $leadClass }}">Etiqueta corta. El tono <code>neutral</code> es la etiqueta de la marca (fondo tinte y texto tinta de la empresa activa); los demás tonos son estados. Siempre lleva texto: el color nunca habla solo.</p>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.chip>Sin revisar</x-ui.chip>
                <x-ui.chip tone="success">Conciliado</x-ui.chip>
                <x-ui.chip tone="warning">Por vencer</x-ui.chip>
                <x-ui.chip tone="danger">Rebotado</x-ui.chip>
                <x-ui.chip tone="info">Programado</x-ui.chip>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.chip :dot="false">Neutro sin punto</x-ui.chip>
                <x-ui.chip tone="success" :dot="false">Aplicado</x-ui.chip>
                <x-ui.chip tone="warning" :dot="false">Pendiente</x-ui.chip>
                <x-ui.chip tone="danger" :dot="false">Ignorado</x-ui.chip>
                <x-ui.chip tone="info" :dot="false">Borrador</x-ui.chip>
            </div>
        </section>

        <section id="component-ui-button" aria-labelledby="component-ui-button-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-button-title" class="{{ $headingClass }}"><code>&lt;x-ui.button&gt;</code></h2>
            <p class="{{ $leadClass }}">Una sola acción principal por vista, en el color de botón de la marca. Todos miden al menos 44 × 44.</p>
            @foreach (['primary' => 'Principal', 'secondary' => 'Secundario', 'ghost' => 'Discreto', 'danger' => 'Peligro'] as $variant => $caption)
                <div class="{{ $specimenClass }}">
                    <p class="{{ $captionClass }}">{{ $caption }} <code class="font-normal">variant="{{ $variant }}"</code></p>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-ui.button :variant="$variant">{{ ['primary' => 'Guardar', 'secondary' => 'Cancelar', 'ghost' => 'Ver más', 'danger' => 'Borrar'][$variant] }}</x-ui.button>
                        <x-ui.button :variant="$variant" :icon="['primary' => 'send', 'secondary' => 'download', 'ghost' => 'filter', 'danger' => 'trash'][$variant]">{{ ['primary' => 'Enviar correo', 'secondary' => 'Exportar', 'ghost' => 'Filtros', 'danger' => 'Borrar compromiso'][$variant] }}</x-ui.button>
                        <x-ui.button :variant="$variant" :icon="['primary' => 'plus', 'secondary' => 'edit', 'ghost' => 'more', 'danger' => 'trash'][$variant]" :icon-only="true">{{ ['primary' => 'Agregar', 'secondary' => 'Editar', 'ghost' => 'Más opciones', 'danger' => 'Borrar'][$variant] }}</x-ui.button>
                        <x-ui.button :variant="$variant" disabled>No disponible</x-ui.button>
                    </div>
                </div>
            @endforeach
        </section>

        <section id="component-ui-link-button" aria-labelledby="component-ui-link-button-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-link-button-title" class="{{ $headingClass }}"><code>&lt;x-ui.link-button&gt;</code></h2>
            <p class="{{ $leadClass }}">Enlace con aspecto de botón, para navegar. Un enlace externo abre otra pestaña y lo dice.</p>
            <div class="flex flex-wrap items-center gap-2">
                <x-ui.link-button href="#component-ui-link-button">Ver clientes</x-ui.link-button>
                <x-ui.link-button href="#component-ui-link-button" variant="secondary" icon="chevron-left">Volver</x-ui.link-button>
                <x-ui.link-button href="#component-ui-link-button" variant="ghost" icon="filter">Filtros</x-ui.link-button>
                <x-ui.link-button href="#component-ui-link-button" variant="danger" icon="legal">Pasar a jurídico</x-ui.link-button>
                <x-ui.link-button href="#component-ui-link-button" variant="secondary" icon="download" :icon-only="true">Descargar Excel</x-ui.link-button>
                <x-ui.link-button href="https://www.dian.gov.co" variant="secondary" :external="true">Consultar en la DIAN</x-ui.link-button>
            </div>
        </section>

        <section id="component-ui-table" aria-labelledby="component-ui-table-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-table-title" class="{{ $headingClass }}"><code>&lt;x-ui.table&gt;</code></h2>
            <p class="{{ $leadClass }}">Las columnas ordenables enlazan a <code>?sort=&amp;direction=</code>; las cifras se alinean a la derecha con <code>data-align="end"</code>. En el celular la tabla se desliza de lado.</p>
            <x-ui.table caption="Clientes con saldo vencido" :columns="$columns" :sort-by="$sortBy" :sort-direction="$sortDirection">
                @foreach ($clients as $client)
                    <tr>
                        <th scope="row">{{ $client['name'] }}</th>
                        <td>{{ $client['tax_id'] }}</td>
                        <td data-align="end"><x-ui.money :value="$client['balance']" /></td>
                        <td data-align="end"><x-ui.money :value="$client['overdue']" /></td>
                        <td data-align="end">{{ $client['days'] }}</td>
                        <td><x-ui.badge-state :state="$client['status']" /></td>
                        <td><x-ui.date :value="$client['last']" fallback="Sin gestión" /></td>
                    </tr>
                @endforeach
                <x-slot:footer>
                    <tr>
                        <th scope="row">Total</th>
                        <td></td>
                        <td data-align="end"><x-ui.money :value="95050000" /></td>
                        <td data-align="end"><x-ui.money :value="31300000" /></td>
                        <td></td>
                        <td></td>
                        <td></td>
                    </tr>
                </x-slot:footer>
            </x-ui.table>
            <x-ui.table caption="Clientes sin datos" :columns="array_slice($columns, 0, 4)" :caption-hidden="true" empty-message="Ningún cliente coincide con los filtros." />
        </section>

        <section id="component-ui-empty" aria-labelledby="component-ui-empty-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-empty-title" class="{{ $headingClass }}"><code>&lt;x-ui.empty&gt;</code></h2>
            <p class="{{ $leadClass }}">Dice qué falta y qué puede hacer la persona.</p>
            <div class="grid items-start gap-4 md:grid-cols-2">
                <x-ui.empty title="No hay compromisos para hoy" description="Los compromisos que registres aparecen aquí el día que vencen." icon="calendar">
                    <x-ui.link-button href="#component-ui-empty" icon="plus">Registrar compromiso</x-ui.link-button>
                </x-ui.empty>
                <x-ui.empty title="Aún no tienes respuestas de clientes" />
            </div>
        </section>

        <section id="component-ui-alert" aria-labelledby="component-ui-alert-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-alert-title" class="{{ $headingClass }}"><code>&lt;x-ui.alert&gt;</code></h2>
            <p class="{{ $leadClass }}">Información y éxito se anuncian sin interrumpir; advertencias y errores, de inmediato.</p>
            <div class="flex flex-col gap-3">
                <x-ui.alert>La carga de cartera de hoy empieza a las 6:00 a. m.</x-ui.alert>
                <x-ui.alert tone="success" title="Compromiso guardado">María Paula ya lo ve en su bandeja.</x-ui.alert>
                <x-ui.alert tone="warning" title="Carga incompleta">Faltan los movimientos de Davivienda y Bancolombia.</x-ui.alert>
                <x-ui.alert tone="danger" :dismissible="true">No pudimos enviar el correo. Revisa la dirección e inténtalo de nuevo.</x-ui.alert>
                <x-ui.alert tone="info" :dismissible="true" title="Nuevo">Ya puedes excluir clientes del cobro semanal.</x-ui.alert>
            </div>
        </section>

        <section id="component-ui-input" aria-labelledby="component-ui-input-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-input-title" class="{{ $headingClass }}"><code>&lt;x-ui.input&gt;</code></h2>
            <p class="{{ $leadClass }}">Etiqueta siempre visible (oculta solo cuando el contexto es obvio, como la búsqueda), ayuda y error enlazados al campo.</p>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.input name="galeria_nombre" label="Nombre del contacto" hint="Como aparece en la factura." :required="true" />
                <x-ui.input name="galeria_valor" label="Valor del compromiso" prefix="$" suffix="COP" inputmode="numeric" value="2.500.000" />
                <x-ui.input name="galeria_correo" label="Correo de pagos" type="email" value="pagos@" />
                <x-ui.input name="galeria_buscar" label="Buscar cliente" type="search" :label-hidden="true" placeholder="Buscar por nombre o NIT" />
                <x-ui.input name="galeria_nit" label="NIT" value="900.123.456-7" disabled hint="Viene de SAP: no se edita aquí." />
                <x-ui.input name="galeria_fecha" label="Fecha del compromiso" type="date" value="2026-10-22" />
            </div>
        </section>

        <section id="component-ui-select" aria-labelledby="component-ui-select-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-select-title" class="{{ $headingClass }}"><code>&lt;x-ui.select&gt;</code></h2>
            <p class="{{ $leadClass }}">Opciones <code>valor =&gt; etiqueta</code>, por ejemplo <code>ClientStatus::options()</code>.</p>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.select name="galeria_filtro" label="Estado del cliente" :options="ClientStatus::options()" :value="ClientStatus::InProgress" />
                <x-ui.select name="galeria_estado" label="Nuevo estado" :options="ClientStatus::options()" placeholder="Elige un estado" :required="true" />
                <x-ui.select name="galeria_asesor" label="Asesor" :options="['10' => 'Carlos Pérez', '20' => 'Laura Martínez']" value="10" disabled />
                <x-ui.select name="galeria_estados[]" label="Varios estados" :options="ClientStatus::options()" :value="[ClientStatus::Overdue, ClientStatus::Defaulted]" multiple size="4" hint="Mantén Ctrl o Cmd para elegir varios." />
            </div>
        </section>

        <section id="component-ui-checkbox" aria-labelledby="component-ui-checkbox-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-checkbox-title" class="{{ $headingClass }}"><code>&lt;x-ui.checkbox&gt;</code></h2>
            <p class="{{ $leadClass }}">Toda la fila es zona táctil. En listas, cada casilla tiene su propio id.</p>
            <div class="grid gap-6 md:grid-cols-2">
                <fieldset class="flex flex-col">
                    <legend class="mb-1 text-[0.8125rem] font-semibold text-fg">Clientes del lote</legend>
                    <x-ui.checkbox name="galeria_clientes[]" value="C-001" label="Ferretería El Tornillo SAS" :checked="true" />
                    <x-ui.checkbox name="galeria_clientes[]" value="C-002" label="Distribuciones Andina Ltda." />
                    <x-ui.checkbox name="galeria_clientes[]" value="C-003" label="Construcciones del Valle SAS" disabled />
                </fieldset>
                <div class="flex flex-col">
                    <x-ui.checkbox name="galeria_aviso" label="El cliente autorizó el envío por correo" hint="Queda en la bitácora." />
                    <x-ui.checkbox name="galeria_ocultar" label="Ocultar «Al día» y «Pago recibido»" :checked="true" hint="Solo en el Kanban." />
                </div>
            </div>
        </section>

        <section id="component-ui-textarea" aria-labelledby="component-ui-textarea-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-textarea-title" class="{{ $headingClass }}"><code>&lt;x-ui.textarea&gt;</code></h2>
            <div class="grid gap-5 md:grid-cols-2">
                <x-ui.textarea name="galeria_observacion" label="Observación" value="Pagará el viernes por transferencia." hint="Máximo 500 caracteres." />
                <x-ui.textarea name="galeria_nota" label="Acuerdo con el cliente" :rows="3" :required="true" />
            </div>
        </section>

        <section id="component-ui-modal" aria-labelledby="component-ui-modal-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-modal-title" class="{{ $headingClass }}"><code>&lt;x-ui.modal&gt;</code></h2>
            <p class="{{ $leadClass }}">Diálogo nativo: se abre con <code>data-modal-open</code> y se cierra con Escape, con el botón o tocando afuera. En el celular sube desde abajo.</p>
            <div class="flex flex-wrap gap-2">
                <x-ui.button variant="danger" icon="trash" data-modal-open="galeria-confirmar">Borrar compromiso</x-ui.button>
                <x-ui.button icon="plus" data-modal-open="galeria-compromiso">Nuevo compromiso</x-ui.button>
            </div>
            <x-ui.modal id="galeria-confirmar" title="¿Borrar el compromiso?">
                <p>El compromiso de Ferretería El Tornillo SAS por <x-ui.money :value="2500000" class="font-semibold" /> se borra de la bitácora. Esta acción no se puede deshacer.</p>
                <x-slot:footer>
                    <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                    <x-ui.button variant="danger" data-modal-close>Borrar</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
            <x-ui.modal id="galeria-compromiso" title="Nuevo compromiso de pago">
                <form id="galeria-compromiso-form" method="GET" action="#component-ui-modal" class="flex flex-col gap-4">
                    <x-ui.input name="galeria_modal_valor" label="Valor" prefix="$" inputmode="numeric" :required="true" />
                    <x-ui.input name="galeria_modal_fecha" label="Fecha en que pagará" type="date" :required="true" />
                    <x-ui.textarea name="galeria_modal_nota" label="Notas" :rows="3" />
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                    <x-ui.button type="submit" form="galeria-compromiso-form">Guardar compromiso</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        </section>

        <section id="component-ui-tabs" aria-labelledby="component-ui-tabs-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-tabs-title" class="{{ $headingClass }}"><code>&lt;x-ui.tabs&gt;</code></h2>
            <p class="{{ $leadClass }}">Cada pestaña es una URL. En pantallas angostas la fila se desliza de lado.</p>
            <x-ui.tabs label="Vistas de clientes" :items="$statusTabs" :current="$currentTab" />
        </section>

        <section id="component-ui-badge-state" aria-labelledby="component-ui-badge-state-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-badge-state-title" class="{{ $headingClass }}"><code>&lt;x-ui.badge-state&gt;</code></h2>
            <p class="{{ $leadClass }}">Estado del cliente, en el orden del Kanban. Los estados que piden atención o cierran el caso (incumplido, pago recibido y jurídico) van con relleno sólido.</p>
            <div class="flex flex-wrap items-center gap-2">
                @foreach (ClientStatus::cases() as $status)
                    <x-ui.badge-state :state="$status" />
                @endforeach
            </div>
        </section>

        <section id="component-ui-money" aria-labelledby="component-ui-money-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-money-title" class="{{ $headingClass }}"><code>&lt;x-ui.money&gt;</code></h2>
            <dl class="grid max-w-md grid-cols-[fit-content(45%)_minmax(0,1fr)] gap-x-6 gap-y-2 text-[0.9375rem] [overflow-wrap:anywhere]">
                <dt class="text-fg-muted">Pesos</dt><dd><x-ui.money :value="1234567" /></dd>
                <dt class="text-fg-muted">Dólares</dt><dd><x-ui.money :value="15890.5" currency="USD" /></dd>
                <dt class="text-fg-muted">Con decimales</dt><dd><x-ui.money :value="1234567.89" :decimals="2" /></dd>
                <dt class="text-fg-muted">Saldo a favor</dt><dd><x-ui.money :value="-250000" /></dd>
                <dt class="text-fg-muted">Sin dato</dt><dd><x-ui.money :value="null" /></dd>
                <dt class="text-fg-muted">Sin dato, otro texto</dt><dd><x-ui.money :value="null" fallback="Sin saldo" /></dd>
            </dl>
        </section>

        <section id="component-ui-date" aria-labelledby="component-ui-date-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-date-title" class="{{ $headingClass }}"><code>&lt;x-ui.date&gt;</code></h2>
            <dl class="grid max-w-md grid-cols-[fit-content(45%)_minmax(0,1fr)] gap-x-6 gap-y-2 text-[0.9375rem] [overflow-wrap:anywhere]">
                <dt class="text-fg-muted">Fecha</dt><dd><x-ui.date :value="$sampleDate" /></dd>
                <dt class="text-fg-muted">Fecha y hora</dt><dd><x-ui.date :value="$sampleDate" :time="true" /></dd>
                <dt class="text-fg-muted">Sin fecha</dt><dd><x-ui.date :value="null" fallback="Sin fecha" /></dd>
            </dl>
        </section>

        <section id="component-ui-icon" aria-labelledby="component-ui-icon-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-icon-title" class="{{ $headingClass }}"><code>&lt;x-ui.icon&gt;</code></h2>
            <p class="{{ $leadClass }}">Estos nombres son el vocabulario de la prop <code>icon</code> y de <code>NavItem::$icon</code>. Sin <code>label</code> el ícono es decorativo; con él, se anuncia como imagen: <x-ui.icon name="warning" label="Atención" class="text-status-maintenance" />.</p>
            <ul class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                @foreach (IconSet::names() as $iconName)
                    <li class="flex min-h-touch items-center gap-3 rounded-control border border-line bg-surface px-3 py-2">
                        <x-ui.icon :name="$iconName" class="size-6" />
                        <code class="min-w-0 text-[0.8125rem] [overflow-wrap:anywhere]">{{ $iconName }}</code>
                    </li>
                @endforeach
            </ul>
        </section>

        <section id="component-ui-pagination" aria-labelledby="component-ui-pagination-title" class="{{ $sectionClass }}">
            <h2 id="component-ui-pagination-title" class="{{ $headingClass }}"><code>&lt;x-ui.pagination&gt;</code></h2>
            <p class="{{ $leadClass }}">Conserva la búsqueda, los filtros y el orden de la URL. Sin total, solo muestra anterior y siguiente.</p>
            <div class="flex flex-col gap-4">
                <x-ui.pagination :paginator="$pages(2)" label="Páginas de ejemplo" />
                <x-ui.pagination :paginator="$pages(1)" label="Primera página de ejemplo" />
                <x-ui.pagination :paginator="$pages(3)" label="Última página de ejemplo" />
                <x-ui.pagination :paginator="$simplePages" label="Páginas sin total" />
            </div>
        </section>
    </x-layouts.app>
@endif
