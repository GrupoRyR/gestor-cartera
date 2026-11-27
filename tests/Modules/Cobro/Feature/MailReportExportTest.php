<?php

use App\Models\User;
use App\Modules\Cobro\Application\Reports\MailReportFilter;
use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Export\SpreadsheetDownload;
use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Cobro\Infrastructure\Persistence\SentMessage;
use App\Modules\Cobro\Infrastructure\Reports\MailReport;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\Data\ClientQuery;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\FakeCarteraRepository;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\CobroUsers;

/*
| GET /cobro/informe/exportar: every item of the collection report, with the
| filters of the page and without its paging, as an Excel file.
*/

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    // The default range is September 23 to October 22.
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);
});

/**
 * A completed run scheduled at $scheduledFor (Bogotá) with one item per
 * entry (status enviado unless given); mailed items get the sent message
 * their replies link to, sent one minute apart.
 *
 * @param  list<array<string, mixed>>  $items
 * @return list<RunItem>
 */
function mailExportRun(CompanyCode $company, RunKind $kind, string $scheduledFor, array $items): array
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
        $clientId = $item['client_id'] ?? 6000 + $run->id * 100 + $index;
        $sentAt = $moment->addMinutes($index);
        $messageId = "<export-{$run->id}-{$index}@gestor-cartera.test>";

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
 * A reply (or bounce) read $minutes after the item was sent, linked to its send.
 */
function mailExportReply(RunItem $item, int $minutes, bool $bounce = false): void
{
    InboundMessage::query()->create([
        'company' => CompanyCode::SGX,
        'mailbox' => 'cobro@empresa.example',
        'graph_id' => 'export-graph-'.uniqid(),
        'from_address' => 'tesoreria@cliente.example',
        'subject' => 'RE: Facturas vencidas',
        'preview' => 'Pagamos el viernes.',
        'received_at' => $item->sent_at?->addMinutes($minutes),
        'is_bounce' => $bounce,
        'sent_message_id' => $item->sent_message_id,
    ]);
}

/**
 * The rows of the only sheet of the downloaded workbook, the header first,
 * each padded to the header width; dates as Y-m-d or Y-m-d H:i and empty
 * cells as null.
 *
 * @return list<list<mixed>>
 */
function mailExportRows(TestResponse $response): array
{
    $reader = new Reader;
    $reader->open($response->baseResponse->getFile()->getPathname());
    $rows = [];

    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_map(fn (mixed $value): mixed => match (true) {
                $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i'),
                $value === '' => null,
                default => $value,
            }, $row->toArray());
        }
    }

    $reader->close();
    $width = count($rows[0] ?? []);

    return array_map(fn (array $row): array => array_pad($row, $width, null), $rows);
}

/**
 * Puts a CarteraRepository in front of $fake that answers as the fake and
 * keeps the queries allClients() receives, in order.
 *
 * @return ArrayObject<int, ClientQuery>
 */
function mailExportAllClientsCalls(FakeCarteraRepository $fake): ArrayObject
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

function mailExport(User $user, array $query = []): TestResponse
{
    return test()->actingAs($user)->get(route('cobro.informe.export', $query));
}

it('serves the export under /cobro/informe/exportar', function () {
    expect(route('cobro.informe.export', absolute: false))->toBe('/cobro/informe/exportar');
});

it('sends guests to the login', function () {
    $this->get('/cobro/informe/exportar')->assertRedirect('/login');
});

it('lets the roles of the report download it', function (Role $role) {
    mailExport(CobroUsers::create($role))->assertOk()
        ->assertHeader('Content-Type', SpreadsheetDownload::MIME_TYPE)
        ->assertDownload('informe-cobro-2026-09-23-a-2026-10-22.xlsx');
})->with([
    'administrador' => [Role::Admin],
    'gerente de cartera' => [Role::PortfolioManager],
    'analista de cartera' => [Role::PortfolioAnalyst],
]);

it('forbids the export without cobro.export, even to who sees the report', function () {
    $viewer = CobroUsers::create(null);
    $viewer->givePermissionTo('cobro.view');

    mailExport($viewer)->assertForbidden();
    mailExport(CobroUsers::create(Role::SalesAdvisor))->assertForbidden();

    $this->actingAs($viewer)->get(route('cobro.informe.index'))->assertOk()->assertDontSee('/cobro/informe/exportar', false);
    $this->fakes->audit->assertNothingRecorded();
});

it('links the page to the export with its current filters', function () {
    $this->actingAs($this->analyst)->get(route('cobro.informe.index', ['tipo' => 'cobro_semanal', 'estado' => 'enviado', 'page' => 2]))->assertOk()
        ->assertSee('href="'.e(route('cobro.informe.export', ['tipo' => 'cobro_semanal', 'estado' => 'enviado'])).'"', false)
        ->assertSeeText('Descargar Excel');
});

it('names the file after the dates of the filter', function () {
    mailExport($this->analyst, ['desde' => '2026-10-01', 'hasta' => '2026-10-15'])->assertOk()
        ->assertDownload('informe-cobro-2026-10-01-a-2026-10-15.xlsx');
});

it('writes a row per item with its run, client, group, advisor, figures, send, first reply, commitments and detail', function () {
    $carlos = Make::advisor(code: 12, name: 'Carlos Pérez');
    $this->fakes->cartera->addAdvisor($carlos)->addClient(Make::clientDetail(Make::client(id: 7001, advisor: $carlos, group: 'Ferreterías')));
    $at = fn (string $moment): CarbonImmutable => CarbonImmutable::parse($moment, 'America/Bogota');
    $this->fakes->compromisos->add(
        Make::commitment(clientId: 7001, createdAt: $at('2026-10-23 08:00:00')),
        Make::commitment(clientId: 7001, createdAt: $at('2026-10-22 15:45:00')),
        // Seven days after the send: outside the window.
        Make::commitment(clientId: 7001, createdAt: $at('2026-10-28 17:00:00')),
    );
    [$answered, $bounced, $failed, $excluded] = mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_id' => 7001, 'client_code' => 'CL-0042', 'client_name' => 'Ferretería El Tornillo SAS', 'tax_id' => '900123456', 'amount' => '2345678.50', 'invoice_count' => 3],
        ['client_name' => 'Rebotado SAS', 'status' => ItemStatus::Bounced, 'bounce_reason' => 'El buzón no existe.', 'bounced_recipient' => 'pagos@cliente.example'],
        ['client_name' => 'Fallido SAS', 'status' => ItemStatus::Failed, 'error' => 'El servidor de correo no respondió.'],
        ['client_name' => 'Excluido SAS', 'status' => ItemStatus::Excluded, 'exclusion_reason' => 'Cliente en cobro jurídico'],
    ]);
    mailExportReply($answered, 30, bounce: true);
    mailExportReply($answered, 150);

    $rows = mailExportRows(mailExport($this->analyst));

    expect($rows[0])->toBe([
        'Fecha del envío', 'Empresa', 'Envío', 'Código del cliente', 'Cliente', 'NIT', 'Grupo', 'Asesor', 'Resultado', 'Valor',
        'Facturas', 'Enviado', 'Primera respuesta', 'Horas hasta la primera respuesta', 'Compromisos en 7 días', 'Primer compromiso', 'Detalle',
    ])->and(count($rows))->toBe(5);

    $byName = collect(array_slice($rows, 1))->keyBy(4);

    expect($byName['Ferretería El Tornillo SAS'])->toEqual([
        '2026-10-21', 'SGX', 'Cobro semanal', 'CL-0042', 'Ferretería El Tornillo SAS', '900123456', 'Ferreterías', 'Carlos Pérez', 'Enviado', 2345678.5,
        3, '2026-10-21 17:00', '2026-10-21 19:30', 2.5, 2, '2026-10-22 15:45', null,
    ])
        ->and($byName['Rebotado SAS'][8])->toBe('Rebotado')
        ->and($byName['Rebotado SAS'][16])->toBe('Rebotó pagos@cliente.example: El buzón no existe.')
        ->and($byName['Rebotado SAS'][12])->toBeNull()
        ->and($byName['Rebotado SAS'][13])->toBeNull()
        ->and($byName['Rebotado SAS'][14])->toEqual(0)
        ->and($byName['Rebotado SAS'][15])->toBeNull()
        ->and($byName['Fallido SAS'][11])->toBeNull()
        ->and($byName['Fallido SAS'][14])->toBeNull()
        ->and($byName['Fallido SAS'][16])->toBe('El servidor de correo no respondió.')
        ->and($byName['Excluido SAS'][16])->toBe('Cliente en cobro jurídico')
        ->and($byName['Excluido SAS'][6])->toBeNull()
        ->and($byName['Excluido SAS'][7])->toBeNull();

    $this->fakes->audit->assertRecorded('cobro.mail_report.exported', fn ($entry): bool => $entry->context['rows'] === 4);
});

it('exports every filtered item, newest run first, beyond one page', function () {
    mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-14 17:00:00', [['client_name' => 'Antiguo SAS']]);
    mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', array_fill(0, 30, []));
    mailExportRun(CompanyCode::SGX, RunKind::Circularization, '2026-10-07 09:00:00', [['client_name' => 'Circularizada SAS']]);
    mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-17 17:00:00', [['client_name' => 'Rebotado SAS', 'status' => ItemStatus::Bounced]]);

    $rows = array_slice(mailExportRows(mailExport($this->analyst, ['tipo' => 'cobro_semanal'])), 1);

    expect($rows)->toHaveCount(32)
        ->and(array_unique(array_column(array_slice($rows, 0, 30), 0)))->toBe(['2026-10-21'])
        ->and(array_column(array_slice($rows, 30), 4))->toBe(['Rebotado SAS', 'Antiguo SAS']);

    $bounced = array_slice(mailExportRows(mailExport($this->analyst, ['tipo' => 'cobro_semanal', 'estado' => 'rebotado'])), 1);

    expect(array_column($bounced, 4))->toBe(['Rebotado SAS']);
});

it('reads the items in chunks by id, without losing or repeating one', function () {
    $older = mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-14 17:00:00', array_fill(0, 3, []));
    $newer = mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', array_fill(0, 4, []));
    mailExportReply($newer[3], 60);
    $filter = new MailReportFilter(
        from: CarbonImmutable::parse('2026-10-01', 'America/Bogota'),
        to: CarbonImmutable::parse('2026-10-31', 'America/Bogota'),
        companies: [CompanyCode::SGX],
    );

    $items = iterator_to_array(app(MailReport::class)->allItems($filter, chunk: 2), preserve_keys: false);
    $ids = fn (array $items): array => array_map(fn (RunItem $item): int => $item->id, $items);

    expect($ids($items))->toBe([...array_reverse($ids($newer)), ...array_reverse($ids($older))])
        ->and($items[0]->first_reply_at?->toDateTimeString())->toBe($newer[3]->sent_at?->addHour()->toDateTimeString())
        ->and($items[0]->relationLoaded('run'))->toBeTrue();
});

it('writes text as text, so a name that looks like a formula never runs', function () {
    mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [['client_name' => '=HYPERLINK("http://ejemplo.invalid","Pague aquí")']]);

    $response = mailExport($this->analyst)->assertOk();
    $zip = new ZipArchive;
    $zip->open($response->baseResponse->getFile()->getPathname());
    $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    expect($sheetXml)->not->toContain('<f>')->and($sheetXml)->not->toContain('<f ')
        ->and(mailExportRows($response)[1][4])->toBe('=HYPERLINK("http://ejemplo.invalid","Pague aquí")');
});

it('reads the advisors with one pass over the clients of each company', function () {
    $this->fakes->currentCompany->switchTo(null);
    $carlos = Make::advisor(code: 12, name: 'Carlos Pérez');
    $sandra = Make::advisor(company: CompanyCode::SDC, code: 12, name: 'Sandra Díaz');
    $this->fakes->cartera->addAdvisor($carlos, $sandra)
        ->addClient(Make::clientDetail(Make::client(id: 7001, advisor: $carlos)))
        ->addClient(Make::clientDetail(Make::client(id: 7002, advisor: $carlos)))
        ->addClient(Make::clientDetail(Make::client(id: 7004, company: CompanyCode::SDC, advisor: $sandra)));
    mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_id' => 7001, 'client_name' => 'Uno SAS'], ['client_id' => 7002, 'client_name' => 'Dos SAS'], ['client_id' => 7003, 'client_name' => 'Sin ficha SAS'],
    ]);
    mailExportRun(CompanyCode::SDC, RunKind::Weekly, '2026-10-14 17:00:00', [['client_id' => 7004, 'client_name' => 'Cuatro SAS']]);
    $calls = mailExportAllClientsCalls($this->fakes->cartera);

    $advisors = collect(array_slice(mailExportRows(mailExport($this->analyst)), 1))->pluck(7, 4)->all();

    // Newest run first and, inside a run, newest item first.
    expect($advisors)->toBe(['Sin ficha SAS' => null, 'Dos SAS' => 'Carlos Pérez', 'Uno SAS' => 'Carlos Pérez', 'Cuatro SAS' => 'Sandra Díaz'])
        ->and(array_map(
            fn (ClientQuery $query): string => ($query->advisorCode ?? 'todos').':'.implode(',', array_map(fn (CompanyCode $company): string => $company->value, $query->companies)),
            $calls->getArrayCopy(),
        ))->toEqualCanonicalizing(['todos:SGX', 'todos:SDC']);
});

it('exports only the items of the SAP group of the filter', function () {
    $this->fakes->cartera
        ->addClient(Make::clientDetail(Make::client(id: 7001, group: 'Ferreterías')))
        ->addClient(Make::clientDetail(Make::client(id: 7002, group: 'Constructoras')));
    mailExportRun(CompanyCode::SGX, RunKind::Weekly, '2026-10-21 17:00:00', [
        ['client_id' => 7001, 'client_name' => 'Uno SAS'], ['client_id' => 7002, 'client_name' => 'Dos SAS'], ['client_id' => 7003, 'client_name' => 'Sin ficha SAS'],
    ]);

    $rows = array_slice(mailExportRows(mailExport($this->analyst, ['grupo' => 'Ferreterías'])), 1);

    expect(array_map(fn (array $row): array => [$row[4], $row[6]], $rows))->toBe([['Uno SAS', 'Ferreterías']]);
});

it('limits the export to 10 a minute per user', function () {
    expect(Route::getRoutes()->getByName('cobro.informe.export')?->gatherMiddleware())->toContain('throttle:cobro-exports');

    foreach (range(1, 10) as $attempt) {
        mailExport($this->analyst)->assertOk();
    }

    mailExport($this->analyst)->assertTooManyRequests();
    mailExport(CobroUsers::create(Role::PortfolioManager, email: 'gerente@segurex.example'))->assertOk();
});
