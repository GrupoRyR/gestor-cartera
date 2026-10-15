@use('App\Modules\Core\Contracts\Enums\ClientStatus')
@use('Carbon\CarbonImmutable')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('Illuminate\Support\MessageBag')
@use('Illuminate\Support\ViewErrorBag')

{{--
    Component gallery (route dev.componentes, local only). One section per
    component of the C.5 contract and its additions, with the main states
    and fictitious sample data, so phase 0b can design them in one place.
--}}

@php
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

    $columns = [
        ['key' => 'name', 'label' => 'Cliente', 'sortable' => true],
        ['key' => 'overdue', 'label' => 'Saldo vencido', 'sortable' => true, 'align' => 'end'],
        ['key' => 'days', 'label' => 'Días de mora', 'align' => 'end'],
        ['key' => 'status', 'label' => 'Estado'],
    ];

    $clients = [
        ['name' => 'Ferretería El Tornillo SAS', 'overdue' => 12500000, 'days' => 45, 'status' => ClientStatus::Overdue],
        ['name' => 'Distribuciones Andina Ltda.', 'overdue' => 3200000, 'days' => 12, 'status' => ClientStatus::Committed],
        ['name' => 'Construcciones del Valle SAS', 'overdue' => 0, 'days' => 0, 'status' => ClientStatus::Current],
    ];

    $tabs = [
        ['key' => 'todos', 'label' => 'Todos', 'href' => '?vista=todos', 'count' => 128],
        ['key' => 'vencidos', 'label' => 'Vencidos', 'href' => '?vista=vencidos', 'count' => 42],
        ['key' => 'compromisos', 'label' => 'Con compromiso', 'href' => '?vista=compromisos'],
    ];

    $paginator = new LengthAwarePaginator(range(1, 25), total: 70, perPage: 25, currentPage: 2, options: ['path' => request()->url()]);
    $sampleDate = CarbonImmutable::parse('2026-10-15 14:30', config('app.timezone'));
@endphp

<x-layouts.app title="Componentes">
    {{-- The page heading is also the <x-ui.page-header> sample: a page has only one <h1>. --}}
    <x-ui.page-header
        title="Componentes"
        context="Solo en local"
        description="Estados principales de cada componente del contrato C.5, con datos de ejemplo."
    >
        <x-slot:actions>
            <x-ui.button icon="download" variant="secondary">Exportar</x-ui.button>
            <x-ui.link-button href="#component-ui-page-header" icon="plus">Nuevo compromiso</x-ui.link-button>
        </x-slot:actions>
    </x-ui.page-header>

    <ul>
        @foreach ($gallerySections as $slug => $tag)
            <li><a href="#component-{{ $slug }}"><code>&lt;{{ $tag }}&gt;</code></a></li>
        @endforeach
    </ul>

    <section id="component-layouts-app" aria-labelledby="component-layouts-app-title">
        <h2 id="component-layouts-app-title"><code>&lt;x-layouts.app&gt;</code></h2>
        <p>Esta misma página usa el layout de la aplicación: encabezado, selector de empresa, navegación lateral, barra inferior en el celular y avisos.</p>
    </section>

    <section id="component-layouts-guest" aria-labelledby="component-layouts-guest-title">
        <h2 id="component-layouts-guest-title"><code>&lt;x-layouts.guest&gt;</code></h2>
        <p>El ingreso y las páginas de error usan el layout sin sesión, siempre con la paleta neutra.</p>
        <x-ui.link-button :href="url('/dev/componentes/pagina-que-no-existe')" variant="secondary">Ver la página 404</x-ui.link-button>
    </section>

    <section id="component-ui-page-header" aria-labelledby="component-ui-page-header-title">
        <h2 id="component-ui-page-header-title"><code>&lt;x-ui.page-header&gt;</code></h2>
        <p>El encabezado de esta página es el ejemplo: contexto, título, descripción y acciones. Cada página tiene un solo <code>&lt;h1&gt;</code>.</p>
    </section>

    <section id="component-ui-card" aria-labelledby="component-ui-card-title">
        <h2 id="component-ui-card-title"><code>&lt;x-ui.card&gt;</code></h2>
        <x-ui.card title="Recaudo de la semana" description="Del lunes 12 al domingo 18 de octubre." :level="3">
            <x-slot:actions>
                <x-ui.button variant="ghost" icon="refresh">Actualizar</x-ui.button>
            </x-slot:actions>
            <p>Llegaron 38 pagos por <x-ui.money :value="184500000" />.</p>
            <x-slot:footer>Fuente: movimientos de bancos.</x-slot:footer>
        </x-ui.card>
        <x-ui.card>
            <p>Bloque sin título: no se anuncia como sección.</p>
        </x-ui.card>
    </section>

    <section id="component-ui-stat" aria-labelledby="component-ui-stat-title">
        <h2 id="component-ui-stat-title"><code>&lt;x-ui.stat&gt;</code></h2>
        <x-ui.stat label="Clientes vencidos" value="42" trend="up" hint="5 más que la semana pasada" tone="warning" />
        <x-ui.stat label="Saldo vencido" trend="down" trend-label="Baja 8 % frente al mes pasado" tone="success">
            <x-ui.money :value="1250000000" />
        </x-ui.stat>
        <x-ui.stat label="Compromisos incumplidos" value="7" trend="flat" tone="danger" href="#component-ui-stat" />
        <x-ui.stat label="Correos enviados hoy" value="120" />
    </section>

    <section id="component-ui-chip" aria-labelledby="component-ui-chip-title">
        <h2 id="component-ui-chip-title"><code>&lt;x-ui.chip&gt;</code></h2>
        <x-ui.chip>Sin revisar</x-ui.chip>
        <x-ui.chip tone="success">Conciliado</x-ui.chip>
        <x-ui.chip tone="warning">Por vencer</x-ui.chip>
        <x-ui.chip tone="danger">Rebotado</x-ui.chip>
        <x-ui.chip tone="info">Programado</x-ui.chip>
        <x-ui.chip tone="info" :dot="false">Sin punto</x-ui.chip>
    </section>

    <section id="component-ui-button" aria-labelledby="component-ui-button-title">
        <h2 id="component-ui-button-title"><code>&lt;x-ui.button&gt;</code></h2>
        <x-ui.button>Guardar</x-ui.button>
        <x-ui.button variant="secondary">Cancelar</x-ui.button>
        <x-ui.button variant="ghost">Ver más</x-ui.button>
        <x-ui.button variant="danger">Borrar</x-ui.button>
        <x-ui.button icon="send">Enviar correo</x-ui.button>
        <x-ui.button icon="search" :icon-only="true">Buscar</x-ui.button>
        <x-ui.button disabled>No disponible</x-ui.button>
    </section>

    <section id="component-ui-link-button" aria-labelledby="component-ui-link-button-title">
        <h2 id="component-ui-link-button-title"><code>&lt;x-ui.link-button&gt;</code></h2>
        <x-ui.link-button href="#component-ui-link-button">Ver clientes</x-ui.link-button>
        <x-ui.link-button href="#component-ui-link-button" variant="secondary">Volver</x-ui.link-button>
        <x-ui.link-button href="#component-ui-link-button" variant="ghost" icon="filter">Filtros</x-ui.link-button>
        <x-ui.link-button href="https://www.dian.gov.co" variant="secondary" :external="true">Consultar en la DIAN</x-ui.link-button>
    </section>

    <section id="component-ui-table" aria-labelledby="component-ui-table-title">
        <h2 id="component-ui-table-title"><code>&lt;x-ui.table&gt;</code></h2>
        <x-ui.table caption="Clientes con saldo vencido" :columns="$columns" sort-by="overdue" sort-direction="desc">
            @foreach ($clients as $client)
                <tr>
                    <td>{{ $client['name'] }}</td>
                    <td data-align="end"><x-ui.money :value="$client['overdue']" /></td>
                    <td data-align="end">{{ $client['days'] }}</td>
                    <td><x-ui.badge-state :state="$client['status']" /></td>
                </tr>
            @endforeach
            <x-slot:footer>
                <tr>
                    <th scope="row">Total</th>
                    <td data-align="end"><x-ui.money :value="15700000" /></td>
                    <td></td>
                    <td></td>
                </tr>
            </x-slot:footer>
        </x-ui.table>
        <x-ui.table caption="Clientes sin datos" :columns="$columns" :caption-hidden="true" empty-message="Ningún cliente coincide con los filtros." />
    </section>

    <section id="component-ui-empty" aria-labelledby="component-ui-empty-title">
        <h2 id="component-ui-empty-title"><code>&lt;x-ui.empty&gt;</code></h2>
        <x-ui.empty title="No hay compromisos para hoy" description="Los compromisos que registres aparecen aquí el día que vencen." icon="calendar">
            <x-ui.link-button href="#component-ui-empty" icon="plus">Registrar compromiso</x-ui.link-button>
        </x-ui.empty>
    </section>

    <section id="component-ui-alert" aria-labelledby="component-ui-alert-title">
        <h2 id="component-ui-alert-title"><code>&lt;x-ui.alert&gt;</code></h2>
        <x-ui.alert>La carga de cartera de hoy empieza a las 6:00 a. m.</x-ui.alert>
        <x-ui.alert tone="success">Guardamos el compromiso de pago.</x-ui.alert>
        <x-ui.alert tone="warning" title="Carga incompleta">Faltan los movimientos de dos bancos.</x-ui.alert>
        <x-ui.alert tone="danger" :dismissible="true">No pudimos enviar el correo. Inténtalo de nuevo.</x-ui.alert>
    </section>

    <section id="component-ui-input" aria-labelledby="component-ui-input-title">
        <h2 id="component-ui-input-title"><code>&lt;x-ui.input&gt;</code></h2>
        <x-ui.input name="galeria_nombre" label="Nombre del contacto" hint="Como aparece en la factura." :required="true" />
        <x-ui.input name="galeria_valor" label="Valor del compromiso" prefix="$" suffix="COP" inputmode="numeric" />
        <x-ui.input name="galeria_correo" label="Correo de pagos" type="email" value="pagos@" />
        <x-ui.input name="galeria_buscar" label="Buscar cliente" type="search" :label-hidden="true" placeholder="Buscar por nombre o NIT" />
    </section>

    <section id="component-ui-select" aria-labelledby="component-ui-select-title">
        <h2 id="component-ui-select-title"><code>&lt;x-ui.select&gt;</code></h2>
        <x-ui.select name="galeria_filtro" label="Estado del cliente" :options="ClientStatus::options()" :value="ClientStatus::InProgress" />
        <x-ui.select name="galeria_estado" label="Nuevo estado" :options="ClientStatus::options()" placeholder="Elige un estado" :required="true" />
    </section>

    <section id="component-ui-checkbox" aria-labelledby="component-ui-checkbox-title">
        <h2 id="component-ui-checkbox-title"><code>&lt;x-ui.checkbox&gt;</code></h2>
        <fieldset>
            <legend>Clientes del lote</legend>
            <x-ui.checkbox name="galeria_clientes[]" value="C-001" label="Ferretería El Tornillo SAS" :checked="true" />
            <x-ui.checkbox name="galeria_clientes[]" value="C-002" label="Distribuciones Andina Ltda." />
        </fieldset>
        <x-ui.checkbox name="galeria_aviso" label="El cliente autorizó el envío por correo" hint="Queda en la bitácora." />
    </section>

    <section id="component-ui-textarea" aria-labelledby="component-ui-textarea-title">
        <h2 id="component-ui-textarea-title"><code>&lt;x-ui.textarea&gt;</code></h2>
        <x-ui.textarea name="galeria_observacion" label="Observación" value="Pagará el viernes por transferencia." hint="Máximo 500 caracteres." />
        <x-ui.textarea name="galeria_nota" label="Acuerdo con el cliente" :rows="3" :required="true" />
    </section>

    <section id="component-ui-modal" aria-labelledby="component-ui-modal-title">
        <h2 id="component-ui-modal-title"><code>&lt;x-ui.modal&gt;</code></h2>
        <x-ui.button variant="danger" data-modal-open="galeria-confirmar">Borrar compromiso</x-ui.button>
        <x-ui.modal id="galeria-confirmar" title="¿Borrar el compromiso?">
            <p>El compromiso de Ferretería El Tornillo SAS por <x-ui.money :value="2500000" /> se borra de la bitácora.</p>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-modal-close>Cancelar</x-ui.button>
                <x-ui.button variant="danger" data-modal-close>Borrar</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    </section>

    <section id="component-ui-tabs" aria-labelledby="component-ui-tabs-title">
        <h2 id="component-ui-tabs-title"><code>&lt;x-ui.tabs&gt;</code></h2>
        <x-ui.tabs label="Vistas de clientes" :items="$tabs" current="vencidos" />
    </section>

    <section id="component-ui-badge-state" aria-labelledby="component-ui-badge-state-title">
        <h2 id="component-ui-badge-state-title"><code>&lt;x-ui.badge-state&gt;</code></h2>
        @foreach (ClientStatus::cases() as $status)
            <x-ui.badge-state :state="$status" />
        @endforeach
    </section>

    <section id="component-ui-money" aria-labelledby="component-ui-money-title">
        <h2 id="component-ui-money-title"><code>&lt;x-ui.money&gt;</code></h2>
        <ul>
            <li>Pesos: <x-ui.money :value="1234567" /></li>
            <li>Dólares: <x-ui.money :value="15890.5" currency="USD" /></li>
            <li>Saldo a favor: <x-ui.money :value="-250000" /></li>
            <li>Sin dato: <x-ui.money :value="null" /></li>
        </ul>
    </section>

    <section id="component-ui-date" aria-labelledby="component-ui-date-title">
        <h2 id="component-ui-date-title"><code>&lt;x-ui.date&gt;</code></h2>
        <ul>
            <li>Fecha: <x-ui.date :value="$sampleDate" /></li>
            <li>Fecha y hora: <x-ui.date :value="$sampleDate" :time="true" /></li>
            <li>Sin fecha: <x-ui.date :value="null" fallback="Sin fecha" /></li>
        </ul>
    </section>

    <section id="component-ui-icon" aria-labelledby="component-ui-icon-title">
        <h2 id="component-ui-icon-title"><code>&lt;x-ui.icon&gt;</code></h2>
        <p>Decorativo: <x-ui.icon name="calendar" /></p>
        <p>Con nombre accesible: <x-ui.icon name="warning" label="Atención" /></p>
    </section>

    <section id="component-ui-pagination" aria-labelledby="component-ui-pagination-title">
        <h2 id="component-ui-pagination-title"><code>&lt;x-ui.pagination&gt;</code></h2>
        <x-ui.pagination :paginator="$paginator" label="Páginas de ejemplo" />
    </section>
</x-layouts.app>
