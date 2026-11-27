<?php

use App\Models\User;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Mail\ReplySender;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    // The default range is September 23 to October 22.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

/**
 * A completed run scheduled at $scheduledFor (Bogotá), stored in the app
 * timezone, with one item per entry (status enviado unless given). Mailed
 * items get the sent message their replies link to, sent one minute apart.
 *
 * @param  list<array<string, mixed>>  $items
 * @return list<RunItem>
 */
function mailReportRun(CompanyCode $company, RunKind $kind, string $scheduledFor, array $items): array
{
    $moment = CarbonImmutable::parse($scheduledFor, 'America/Bogota')->setTimezone((string) config('app.timezone'));

    $run = Run::query()->create([
        'company' => $company,
        'kind' => $kind,
        'status' => RunStatus::Completed,
        'scheduled_for' => $moment,
        'started_at' => $moment,
        'finished_at' => $moment->addMinutes(30),
    ]);

    $created = [];

    foreach ($items as $index => $item) {
        $status = $item['status'] ?? ItemStatus::Sent;
        $clientId = $item['client_id'] ?? 5000 + $run->id * 100 + $index;
        $sentAt = $moment->addMinutes($index);
        $messageId = "<report-{$run->id}-{$index}@gestor-cartera.test>";

        $sent = in_array($status, [ItemStatus::Sent, ItemStatus::Bounced], true) ? SentMessage::query()->create([
            'company' => $company,
            'mailbox' => 'cobro@empresa.example',
            'client_id' => $clientId,
            'category' => $kind->value,
            'subject' => 'Facturas vencidas',
            'recipients' => ['tesoreria@cliente.example'],
            'internet_message_id' => $messageId,
            'sent_at' => $sentAt,
        ]) : null;

        $created[] = RunItem::query()->create(array_merge([
            'client_code' => 'C'.$clientId,
            'client_name' => 'Cliente '.$clientId,
            'tax_id' => '900'.$clientId,
            'recipients' => ['tesoreria@cliente.example'],
            'amount' => '1500000.00',
            'invoice_count' => 2,
        ], Arr::except($item, ['status', 'client_id']), [
            'run_id' => $run->id,
            'client_id' => $clientId,
            'status' => $status,
            'sent_message_id' => $sent?->id,
            'internet_message_id' => $sent === null ? null : $messageId,
            'sent_at' => $sent === null ? null : $sentAt,
        ]));
    }

    return $created;
}

/**
 * A message read from the inbox $minutes after the item was sent, linked to
 * the item's send.
 */
function mailReportReply(RunItem $item, int $minutes, bool $bounce = false): InboundMessage
{
    static $sequence = 0;
    $sequence++;

    $sent = SentMessage::query()->where('internet_message_id', $item->internet_message_id)->firstOrFail();

    return InboundMessage::query()->create([
        'company' => $sent->company,
        'mailbox' => $sent->mailbox,
        'graph_id' => "report-graph-{$sequence}",
        'from_address' => $bounce ? 'postmaster@cliente.example' : 'tesoreria@cliente.example',
        'subject' => $bounce ? 'Undeliverable: Facturas vencidas' : 'RE: Facturas vencidas',
        'preview' => $bounce ? 'Delivery has failed.' : 'Pagamos el viernes.',
        'received_at' => $item->sent_at?->addMinutes($minutes),
        'is_bounce' => $bounce,
        'sent_message_id' => $sent->id,
    ]);
}

/**
 * A reply the team sent from the app (ReplySender) at $sentAt (Bogotá).
 */
function mailReportTeamReply(CompanyCode $company, string $sentAt, string $category = ReplySender::CATEGORY): void
{
    SentMessage::query()->create([
        'company' => $company,
        'mailbox' => 'cobro@empresa.example',
        'category' => $category,
        'subject' => 'RE: Facturas vencidas',
        'recipients' => ['tesoreria@cliente.example'],
        'internet_message_id' => '<team-'.uniqid().'@gestor-cartera.test>',
        'sent_at' => CarbonImmutable::parse($sentAt, 'America/Bogota')->setTimezone((string) config('app.timezone')),
    ]);
}

/**
 * The value shown in the metric tile data-metric="$key".
 *
 * @param  TestResponse<*>  $response
 */
function mailReportMetric(TestResponse $response, string $key): string
{
    $matched = preg_match('/data-metric="'.$key.'".*?data-part="value"[^>]*>(.*?)<\/span>/s', (string) $response->getContent(), $match);

    expect($matched)->toBe(1, "No metric tile [{$key}].");

    return VisibleText::of($match[1]);
}

/**
 * Puts a CarteraRepository in front of $fake that answers as the fake and
 * keeps the queries allClients() receives, in order.
 *
 * @return ArrayObject<int, ClientQuery>
 */
function mailReportAllClientsCalls(FakeCarteraRepository $fake): ArrayObject
{
    $calls = new ArrayObject;
    $cartera = Mockery::mock(CarteraRepository::class);

    foreach ((new ReflectionClass(CarteraRepository::class))->getMethods() as $method) {
        $name = $method->getName();
        $cartera->shouldReceive($name)->andReturnUsing(function (mixed ...$arguments) use ($fake, $name, $calls): mixed {
            if ($name === 'allClients') {
                $calls[] = $arguments[0];
            }

            return $fake->{$name}(...$arguments);
        });
    }

    app()->instance(CarteraRepository::class, $cartera);

    return $calls;
}

function mailReport(User $user, array $query = []): TestResponse
{
    return test()->actingAs($user)->get(route('cobro.informe.index', $query));
}

it('serves the report under /cobro/informe', function () {
    expect(route('cobro.informe.index', absolute: false))->toBe('/cobro/informe');
});

it('shows the report to the roles with cobro.view', function (Role $role) {
    mailReport(CobroUsers::create($role))->assertOk()->assertSeeText('Informe de cobro');
})->with([
    'administrador' => [Role::Admin],
    'gerente de cartera' => [Role::PortfolioManager],
    'analista de cartera' => [Role::PortfolioAnalyst],
]);

it('forbids the report to roles without cobro.view', function (?Role $role) {
    mailReport(CobroUsers::create($role))->assertForbidden();
})->with(CobroUsers::notViewers());

it('sends guests to the login', function () {
    $this->get('/cobro/informe')->assertRedirect('/login');
});

it('lists the report in the Cobro menu, next to the delivery reports', function () {
    $keys = fn (User $user): array => array_map(fn (NavItem $item): string => $item->key, app(NavRegistry::class)->visibleTo($user));
    $item = collect(app(NavRegistry::class)->all())->firstWhere('key', 'cobro.informe');

    expect($keys($this->analyst))->toContain('cobro.informe')
        ->and($keys(CobroUsers::create(Role::SalesAdvisor)))->not->toContain('cobro.informe')
        ->and([$item?->label, $item?->route, $item?->group, $item?->order, $item?->permission])
        ->toBe(['Informe de cobro', 'cobro.informe.index', 'Cobro', 132, 'cobro.view']);
});

it('covers the last 30 days by default, by the scheduled Bogotá day', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-09-22 17:00:00', [['client_name' => 'Fuera del rango SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-09-23 00:00:00', [['client_name' => 'Primer día SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Circularization, '2026-10-01 09:00:00', [['client_name' => 'Circularizada SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Ferretería El Tornillo SAS']]);

    mailReport($this->analyst)->assertOk()
        ->assertSee('value="2026-09-23"', false)
        ->assertSee('value="2026-10-22"', false)
        ->assertSeeInOrder(['Ferretería El Tornillo SAS', 'Circularizada SAS', 'Primer día SAS'])
        ->assertDontSeeText('Fuera del rango SAS');
});

it('reads only a start date after today as that one day', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-25 17:00:00', [['client_name' => 'Domingo SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-26 17:00:00', [['client_name' => 'Lunes SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Miércoles SAS']]);

    mailReport($this->analyst, ['desde' => '2026-10-25'])->assertOk()
        ->assertSee('value="2026-10-25"', false)
        ->assertDontSee('value="2026-10-22"', false)
        ->assertSeeText('Domingo SAS')
        ->assertDontSeeText('Lunes SAS')
        ->assertDontSeeText('Miércoles SAS');

    mailReport($this->analyst, ['desde' => '2026-10-01'])->assertOk()
        ->assertSee('value="2026-10-22"', false)
        ->assertSeeText('Miércoles SAS')
        ->assertDontSeeText('Domingo SAS');

    mailReport($this->analyst, ['desde' => '2026-10-25', 'hasta' => '2026-10-24'])->assertSessionHasErrors('hasta');
});

it('filters by the date range', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-09-22 17:00:00', [['client_name' => 'Septiembre SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Octubre SAS']]);

    mailReport($this->analyst, ['desde' => '2026-09-01', 'hasta' => '2026-09-30'])->assertOk()
        ->assertSeeText('Septiembre SAS')
        ->assertDontSeeText('Octubre SAS');
});

it('rejects invalid filters', function (array $query, string $field) {
    mailReport($this->analyst, $query)->assertSessionHasErrors($field);
})->with([
    'a date that is not a date' => [['desde' => '22/10/2026'], 'desde'],
    'a range that ends before it starts' => [['desde' => '2026-10-10', 'hasta' => '2026-10-01'], 'hasta'],
    'another kind of mail' => [['tipo' => 'prueba'], 'tipo'],
    'an unknown status' => [['estado' => 'leido'], 'estado'],
    'an answer other than si or no' => [['respondio' => 'tal vez'], 'respondio'],
    'a company out of scope' => [['empresa' => 'SDC'], 'empresa'],
    'an advisor the user does not see' => [['asesor' => 'SGX:99'], 'asesor'],
    'a group no client in scope has' => [['grupo' => 'Inexistente'], 'grupo'],
]);

it('never shows a company out of the user scope', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Ferretería El Tornillo SAS']]);
    [$sdc] = mailReportRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Químicos del Norte SAS']]);
    mailReportReply($sdc, 60);

    $response = mailReport($this->analyst)->assertOk()
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertDontSeeText('Químicos del Norte SAS');

    expect(mailReportMetric($response, 'sent'))->toBe('1')
        ->and(mailReportMetric($response, 'replies'))->toBe('0');
});

it('filters by company in the group view', function () {
    $this->fakes->currentCompany->switchTo(null);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Ferretería El Tornillo SAS']]);
    mailReportRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Químicos del Norte SAS']]);

    mailReport($this->analyst)->assertOk()->assertSeeText('Ferretería El Tornillo SAS')->assertSeeText('Químicos del Norte SAS');
    mailReport($this->analyst, ['empresa' => 'SDC'])->assertOk()
        ->assertSeeText('Químicos del Norte SAS')
        ->assertDontSeeText('Ferretería El Tornillo SAS');
});

it('finds a client by code, name or NIT', function (string $search) {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_code' => 'CL-0042', 'client_name' => 'Ferretería El Tornillo SAS', 'tax_id' => '900123456'],
        ['client_code' => 'CL-0077', 'client_name' => 'Distribuciones Andinas SAS', 'tax_id' => '800999888'],
    ]);

    mailReport($this->analyst, ['cliente' => $search])->assertOk()
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertDontSeeText('Distribuciones Andinas SAS');
})->with(['code' => 'cl-0042', 'name' => 'tornillo', 'NIT' => '900123']);

it('reads % and _ in the client search as themselves', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => 'Ferretería El Tornillo SAS']]);

    mailReport($this->analyst, ['cliente' => '%'])->assertOk()->assertDontSeeText('Ferretería El Tornillo SAS');
    mailReport($this->analyst, ['cliente' => 'El_Tornillo'])->assertOk()->assertDontSeeText('Ferretería El Tornillo SAS');
});

it('filters by advisor of the company and shows each client current advisor', function () {
    $this->fakes->currentCompany->switchTo(null);
    $carlos = Make::advisor(code: 12, name: 'Carlos Pérez');
    $sdcAdvisor = Make::advisor(company: CompanyCode::SDC, code: 12, name: 'Sandra Díaz');
    $this->fakes->cartera
        ->addAdvisor($carlos, $sdcAdvisor, Make::advisor(code: 13, name: 'Diana Gómez'))
        ->addClient(Make::clientDetail(Make::client(id: 7001, advisor: $carlos)))
        ->addClient(Make::clientDetail(Make::client(id: 7002, advisor: Make::advisor(code: 13, name: 'Diana Gómez'))))
        ->addClient(Make::clientDetail(Make::client(id: 7004, company: CompanyCode::SDC, advisor: $sdcAdvisor)));

    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_id' => 7001, 'client_name' => 'Cliente de Carlos SAS'],
        ['client_id' => 7002, 'client_name' => 'Cliente de Diana SAS'],
        ['client_id' => 7003, 'client_name' => 'Cliente sin ficha SAS'],
    ]);
    mailReportRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-21 17:00:00', [['client_id' => 7004, 'client_name' => 'Cliente de Sandra SAS']]);

    $all = mailReport($this->analyst)->assertOk()->assertSee('value="SGX:12"', false)->assertSee('value="SDC:12"', false);
    $html = (string) $all->getContent();
    $row = fn (string $name): string => VisibleText::of(substr($html, (int) strrpos(substr($html, 0, (int) strpos($html, $name)), '<tr'), 1500));

    expect($row('Cliente de Carlos SAS'))->toContain('Carlos Pérez')
        ->and($row('Cliente sin ficha SAS'))->toContain('Cliente sin ficha SAS —');

    mailReport($this->analyst, ['asesor' => 'SGX:12'])->assertOk()
        ->assertSeeText('Cliente de Carlos SAS')
        ->assertDontSeeText('Cliente de Diana SAS')
        ->assertDontSeeText('Cliente sin ficha SAS')
        ->assertDontSeeText('Cliente de Sandra SAS');
});

it('reads the clients from Core with one pass per company in scope, whatever the filters', function () {
    $carlos = Make::advisor(code: 12, name: 'Carlos Pérez');
    $this->fakes->cartera->addAdvisor($carlos)->addClient(Make::clientDetail(Make::client(id: 7001, advisor: $carlos, group: 'Ferreterías')));
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_id' => 7001, 'client_name' => 'Cliente de Carlos SAS'], ['client_name' => 'Otro SAS']]);
    $calls = mailReportAllClientsCalls($this->fakes->cartera);

    $response = mailReport($this->analyst, ['asesor' => 'SGX:12', 'grupo' => 'Ferreterías'])->assertOk()
        ->assertSeeText('Cliente de Carlos SAS')
        ->assertDontSeeText('Otro SAS');

    expect(mailReportMetric($response, 'sent'))->toBe('1')
        ->and(array_map(
            fn (ClientQuery $query): string => ($query->advisorCode ?? 'todos').':'.($query->group ?? 'todos').':'.implode(',', array_map(fn (CompanyCode $company): string => $company->value, $query->companies)),
            $calls->getArrayCopy(),
        ))->toBe(['todos:todos:SGX']);
});

it('filters by the current SAP group of the client and shows it on each row', function () {
    $this->fakes->currentCompany->switchTo(null);
    $this->fakes->cartera
        ->addClient(Make::clientDetail(Make::client(id: 7001, group: 'Ferreterías')))
        ->addClient(Make::clientDetail(Make::client(id: 7002, group: 'Constructoras')))
        ->addClient(Make::clientDetail(Make::client(id: 7004, company: CompanyCode::SDC, group: 'Ferreterías')));

    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_id' => 7001, 'client_name' => 'Ferretería Uno SAS'],
        ['client_id' => 7002, 'client_name' => 'Constructora Dos SAS'],
        ['client_id' => 7003, 'client_name' => 'Cliente sin ficha SAS'],
    ]);
    mailReportRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-21 17:00:00', [['client_id' => 7004, 'client_name' => 'Ferretería Cuatro SAS']]);

    $all = mailReport($this->analyst)->assertOk()
        ->assertSee('value="Constructoras"', false)
        ->assertSee('value="Ferreterías"', false);
    $html = (string) $all->getContent();
    $row = fn (string $name): string => VisibleText::of(substr($html, (int) strrpos(substr($html, 0, (int) strpos($html, $name)), '<tr'), 1500));

    expect($row('Ferretería Uno SAS'))->toContain('Ferretería Uno SAS Ferreterías')
        ->and($row('Cliente sin ficha SAS'))->toContain('Cliente sin ficha SAS — —');

    $ferreterias = mailReport($this->analyst, ['grupo' => 'Ferreterías'])->assertOk()
        ->assertSeeText('Ferretería Uno SAS')
        ->assertSeeText('Ferretería Cuatro SAS')
        ->assertDontSeeText('Constructora Dos SAS')
        ->assertDontSeeText('Cliente sin ficha SAS');

    expect(mailReportMetric($ferreterias, 'sent'))->toBe('2');

    mailReport($this->analyst, ['grupo' => 'Ferreterías', 'empresa' => 'SDC'])->assertOk()
        ->assertSeeText('Ferretería Cuatro SAS')
        ->assertDontSeeText('Ferretería Uno SAS');
});

it('counts the mails followed by a commitment of the client within 7 days and shows the first one', function () {
    [$soon, $late, $before, $bounced, $failed, $twice] = mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-14 17:00:00', [
        ['client_id' => 9001, 'client_name' => 'Pronto SAS'],
        ['client_id' => 9002, 'client_name' => 'Tarde SAS'],
        ['client_id' => 9003, 'client_name' => 'Antes SAS'],
        ['client_id' => 9004, 'client_name' => 'Rebotado SAS', 'status' => ItemStatus::Bounced],
        ['client_id' => 9005, 'client_name' => 'Fallido SAS', 'status' => ItemStatus::Failed],
        ['client_id' => 9006, 'client_name' => 'Dos veces SAS'],
    ]);
    $at = fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment, 'America/Bogota');
    $this->fakes->compromisos->add(
        Make::commitment(clientId: 9001, createdAt: $at('2026-10-16 10:15:00')),
        // Exactly 7 days after the send (17:01): outside the window.
        Make::commitment(clientId: 9002, createdAt: $at('2026-10-21 17:01:00')),
        Make::commitment(clientId: 9003, createdAt: $at('2026-10-14 16:59:00')),
        Make::commitment(clientId: 9004, createdAt: $at('2026-10-15 08:00:00')),
        Make::commitment(clientId: 9005, createdAt: $at('2026-10-15 08:00:00')),
        Make::commitment(clientId: 9006, createdAt: $at('2026-10-20 09:00:00')),
        Make::commitment(clientId: 9006, createdAt: $at('2026-10-15 11:30:00')),
    );

    $response = mailReport($this->analyst)->assertOk();
    $html = (string) $response->getContent();
    $row = fn (RunItem $item): string => VisibleText::of(substr($html, (int) strpos($html, '>', (int) strpos($html, 'data-item="'.$item->id.'"')) + 1, 2500));

    // Mailed: enviado and rebotado (5); with a commitment in the 7 days after the send: Pronto, Rebotado and Dos veces.
    expect(mailReportMetric($response, 'commitments'))->toBe('3')
        ->and(mailReportMetric($response, 'commitment_rate'))->toBe('60 %')
        ->and($row($soon))->toContain('Sin respuesta 16/10/2026 10:15')
        ->and($row($twice))->toContain('Sin respuesta 15/10/2026 11:30')
        ->and($row($late))->toContain('Sin respuesta —')
        ->and($row($before))->toContain('Sin respuesta —')
        ->and($row($failed))->toContain('Sin respuesta —')
        ->and($row($bounced))->toContain('Sin respuesta 15/10/2026 08:00');
});

it('shows no commitments and a dash for their rate without mails', function () {
    $response = mailReport($this->analyst)->assertOk();

    expect(mailReportMetric($response, 'commitments'))->toBe('0')
        ->and(mailReportMetric($response, 'commitment_rate'))->toBe('—');
});

it('filters by kind of mail and by status', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_name' => 'Ferretería El Tornillo SAS'],
        ['client_name' => 'Distribuciones Andinas SAS', 'status' => ItemStatus::Bounced],
    ]);
    mailReportRun(CompanyCode::SGX, RunKind::Circularization, '2026-10-07 09:00:00', [['client_name' => 'Circularizada SAS']]);

    mailReport($this->analyst, ['tipo' => 'circularizacion'])->assertOk()
        ->assertSeeText('Circularizada SAS')
        ->assertDontSeeText('Ferretería El Tornillo SAS');

    mailReport($this->analyst, ['estado' => 'rebotado'])->assertOk()
        ->assertSeeText('Distribuciones Andinas SAS')
        ->assertDontSeeText('Ferretería El Tornillo SAS')
        ->assertDontSeeText('Circularizada SAS');
});

it('filters the mailed clients by whether they replied', function () {
    [$answered, $silent, $bounced] = mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_name' => 'Respondió SAS'],
        ['client_name' => 'No respondió SAS'],
        ['client_name' => 'Solo rebotó SAS', 'status' => ItemStatus::Bounced],
        ['client_name' => 'Sin correo SAS', 'status' => ItemStatus::NoEmail],
    ]);
    mailReportReply($answered, 90);
    mailReportReply($bounced, 5, bounce: true);

    mailReport($this->analyst, ['respondio' => 'si'])->assertOk()
        ->assertSeeText('Respondió SAS')
        ->assertDontSeeText('No respondió SAS')
        ->assertDontSeeText('Solo rebotó SAS');

    mailReport($this->analyst, ['respondio' => 'no'])->assertOk()
        ->assertSeeText('No respondió SAS')
        ->assertSeeText('Solo rebotó SAS')
        ->assertDontSeeText('Respondió SAS')
        ->assertDontSeeText('Sin correo SAS');
});

it('shows each item with its run, amount, invoices, send and first reply', function () {
    [$item, $silent] = mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_code' => 'CL-0042', 'client_name' => 'Ferretería El Tornillo SAS', 'amount' => '2345678.00', 'invoice_count' => 3],
        ['client_name' => 'Callado SAS'],
    ]);
    mailReportReply($item, 150);
    mailReportReply($item, 30, bounce: true);
    mailReportReply($item, 600);

    $html = (string) mailReport($this->analyst)->assertOk()->getContent();
    $row = fn (RunItem $item): string => VisibleText::of(substr($html, (int) strpos($html, '>', (int) strpos($html, 'data-item="'.$item->id.'"')) + 1, 2500));

    expect($row($item))->toStartWith('21/10/2026 SGX Cobro semanal CL-0042 Ferretería El Tornillo SAS — — Enviado $ 2.345.678 3 21/10/2026 17:00 21/10/2026 19:30 —')
        ->and($row($silent))->toContain('Sin respuesta');
});

it('counts the sends, failures, bounces and clients reached of every filtered item', function () {
    $this->fakes->currentCompany->switchTo(null);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-14 17:00:00', [
        ['client_id' => 9001], ['client_id' => 9002, 'status' => ItemStatus::Bounced], ['client_id' => 9003, 'status' => ItemStatus::Failed],
    ]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_id' => 9001], ['client_id' => 9003, 'status' => ItemStatus::Failed], ['client_id' => 9004, 'status' => ItemStatus::NoEmail],
        ['client_id' => 9005, 'status' => ItemStatus::Excluded],
    ]);
    mailReportRun(CompanyCode::SDC, RunKind::Circularization, '2026-10-07 09:00:00', [['client_id' => 9006, 'status' => ItemStatus::Bounced]]);

    $response = mailReport($this->analyst)->assertOk();

    expect(mailReportMetric($response, 'sent'))->toBe('4')
        ->and(mailReportMetric($response, 'failed'))->toBe('2')
        ->and(mailReportMetric($response, 'bounced'))->toBe('2')
        ->and(mailReportMetric($response, 'clients'))->toBe('3');

    $sdc = mailReport($this->analyst, ['empresa' => 'SDC']);

    expect([mailReportMetric($sdc, 'sent'), mailReportMetric($sdc, 'bounced'), mailReportMetric($sdc, 'clients')])->toBe(['1', '1', '1']);
});

it('counts the replies by send or by Message-ID, the reply rate and the median time to the first reply', function () {
    $items = mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [[], [], [], [], [], ['status' => ItemStatus::Failed]]);
    mailReportReply($items[0], 120);
    mailReportReply($items[0], 30, bounce: true);
    mailReportReply($items[0], 900);
    mailReportReply($items[1], 240);
    mailReportReply($items[2], 540);
    mailReportReply($items[3], 10, bounce: true);
    // SendRunItem links the send after marking the item sent; when that
    // update failed only the Message-ID is left.
    $items[2]->update(['sent_message_id' => null]);

    $response = mailReport($this->analyst)->assertOk();

    // Replies after 2 h, 4 h and 9 h: the median is 4 h.
    expect(mailReportMetric($response, 'replies'))->toBe('3')
        ->and(mailReportMetric($response, 'rate'))->toBe('60 %')
        ->and(mailReportMetric($response, 'median'))->toBe('4 h');

    $items[2]->delete();

    // After 2 h and 4 h: 3 h; then 2 h and 5 days: 61 h is 2,5 days.
    expect(mailReportMetric(mailReport($this->analyst), 'median'))->toBe('3 h');

    InboundMessage::query()->where('sent_message_id', $items[1]->sent_message_id)->update(['received_at' => $items[1]->sent_at?->addDays(5)]);

    expect(mailReportMetric(mailReport($this->analyst), 'median'))->toBe('2,5 días')
        ->and(mailReportMetric(mailReport($this->analyst), 'rate'))->toBe('50 %');
});

it('shows a dash for the rate and the median without sends or replies', function () {
    $response = mailReport($this->analyst)->assertOk()->assertSeeText('No hay correos con estos filtros');

    expect(mailReportMetric($response, 'sent'))->toBe('0')
        ->and(mailReportMetric($response, 'rate'))->toBe('—')
        ->and(mailReportMetric($response, 'median'))->toBe('—');

    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [[]]);
    $silent = mailReport($this->analyst);

    expect(mailReportMetric($silent, 'rate'))->toBe('0 %')
        ->and(mailReportMetric($silent, 'median'))->toBe('—');
});

it('counts the replies the team sent in the range and company, whatever the other filters', function () {
    $this->fakes->currentCompany->switchTo(null);
    mailReportTeamReply(CompanyCode::SGX, '2026-09-23 00:05:00');
    mailReportTeamReply(CompanyCode::SGX, '2026-10-22 08:00:00');
    mailReportTeamReply(CompanyCode::SDC, '2026-10-10 10:00:00');
    mailReportTeamReply(CompanyCode::SGX, '2026-09-22 23:55:00');
    mailReportTeamReply(CompanyCode::SGX, '2026-10-10 10:00:00', category: RunKind::Weekly->value);

    expect(mailReportMetric(mailReport($this->analyst), 'team'))->toBe('3')
        ->and(mailReportMetric(mailReport($this->analyst, ['empresa' => 'SGX', 'tipo' => 'circularizacion', 'estado' => 'fallido']), 'team'))->toBe('2')
        ->and(mailReportMetric(mailReport($this->analyst, ['desde' => '2026-10-01', 'hasta' => '2026-10-31']), 'team'))->toBe('2');

    $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

    expect(mailReportMetric(mailReport($this->analyst), 'team'))->toBe('1');
});

it('pages 25 items, newest first, keeping the filters in the page links', function () {
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-14 17:00:00', [['client_name' => 'Antiguo SAS']]);
    mailReportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', array_fill(0, 25, []));

    $first = mailReport($this->analyst, ['tipo' => 'cobro_semanal'])->assertOk()
        ->assertDontSeeText('Antiguo SAS')
        ->assertSee('tipo=cobro_semanal&amp;page=2', false);

    expect(mailReportMetric($first, 'sent'))->toBe('26');

    mailReport($this->analyst, ['tipo' => 'cobro_semanal', 'page' => 2])->assertOk()->assertSeeText('Antiguo SAS');
});
