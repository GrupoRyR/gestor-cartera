<?php

use App\Modules\Cobro\Database\Seeders\CobroPermissionSeeder;
use App\Modules\Cobro\Domain\Runs\ItemStatus;
use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Runs\RunStatus;
use App\Modules\Cobro\Infrastructure\Persistence\Run;
use App\Modules\Cobro\Infrastructure\Persistence\RunItem;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cobro\Support\CobroUsers;
use Tests\Modules\Cobro\Support\VisibleText;

beforeEach(function () {
    $this->seed(CobroPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = CobroUsers::create(Role::PortfolioAnalyst);

    $this->run = cobroRun(CompanyCode::SGX, [
        ['client_name' => 'Ferretería El Tornillo SAS', 'status' => ItemStatus::Sent, 'recipients' => ['tesoreria@tornillo.example'], 'sent_at' => '2026-10-21 17:00:05'],
        ['client_name' => 'Distribuciones Andinas SAS', 'status' => ItemStatus::Bounced, 'recipients' => ['pagos@andinas.example'],
            'bounce_reason' => "Remote server returned '550 5.1.1 User unknown'", 'bounced_recipient' => 'pagos@andinas.example'],
        ['client_name' => 'Comercial Sin Correo SAS', 'status' => ItemStatus::NoEmail, 'recipients' => []],
        ['client_name' => 'Comercializadora Excluida SAS', 'status' => ItemStatus::Excluded, 'exclusion_reason' => 'Proceso jurídico'],
        ['client_name' => 'Industrias Fallidas SAS', 'status' => ItemStatus::Failed, 'error' => 'Graph did not send the mail: HTTP 429.'],
    ]);
});

/**
 * A completed weekly run with these items.
 *
 * @param  list<array<string, mixed>>  $items
 */
function cobroRun(CompanyCode $company, array $items, string $scheduledFor = '2026-10-21 17:00:00'): Run
{
    $run = Run::query()->create([
        'company' => $company,
        'kind' => RunKind::Weekly,
        'status' => RunStatus::Completed,
        'scheduled_for' => CarbonImmutable::parse($scheduledFor, 'America/Bogota'),
        'cutoff_date' => '2026-10-20',
        'started_at' => CarbonImmutable::parse($scheduledFor, 'America/Bogota'),
        'finished_at' => CarbonImmutable::parse($scheduledFor, 'America/Bogota')->addMinutes(3),
    ]);

    foreach ($items as $index => $item) {
        RunItem::query()->create(array_merge([
            'run_id' => $run->id,
            'client_id' => 1000 + $run->id * 100 + $index,
            'client_code' => 'C'.$index,
            'tax_id' => '900'.$index,
            'recipients' => ['contacto@cliente.example'],
            'amount' => '1500000.00',
            'invoice_count' => 1,
        ], $item));
    }

    return $run;
}

it('serves the reports under /cobro/lotes', function () {
    expect(route('cobro.lotes.index', absolute: false))->toBe('/cobro/lotes')
        ->and(route('cobro.lotes.show', 5, absolute: false))->toBe('/cobro/lotes/5');
});

it('forbids the reports to roles without cobro.view', function (?Role $role) {
    $user = CobroUsers::create($role);

    $this->actingAs($user)->get('/cobro/lotes')->assertForbidden();
    $this->actingAs($user)->get("/cobro/lotes/{$this->run->id}")->assertForbidden();
})->with(CobroUsers::notViewers());

it('lists the runs with their delivery counts', function () {
    cobroRun(CompanyCode::SGX, [['client_name' => 'Otro', 'status' => ItemStatus::Sent]], '2026-10-14 17:00:00');

    $response = $this->actingAs($this->analyst)->get('/cobro/lotes')->assertOk()
        ->assertSeeText('Informes de envío')
        ->assertSeeInOrder(['21/10/2026 17:00', '14/10/2026 17:00']);

    $html = (string) $response->getContent();
    $row = substr($html, (int) strpos($html, 'data-run="'.$this->run->id.'"'), 2500);

    expect(VisibleText::of($row))->toContain('Cobro semanal Terminado 1 1 1 1 1');
});

it('hides the runs of companies out of the user scope', function () {
    $sdc = cobroRun(CompanyCode::SDC, [['client_name' => 'Químicos del Norte SAS', 'status' => ItemStatus::Sent]]);

    $this->actingAs($this->analyst)->get('/cobro/lotes')->assertOk()->assertDontSee('data-run="'.$sdc->id.'"', false);
    $this->actingAs($this->analyst)->get("/cobro/lotes/{$sdc->id}")->assertNotFound();
});

it('reports the run client by client, with the bounce reason read from the mailbox', function () {
    $this->actingAs($this->analyst)->get("/cobro/lotes/{$this->run->id}")->assertOk()
        ->assertSeeText('Cobro semanal del 21/10/2026')
        ->assertSeeText('Ferretería El Tornillo SAS')
        ->assertSeeText("Rebotó pagos@andinas.example: Remote server returned '550 5.1.1 User unknown'")
        ->assertSeeText('Comercial Sin Correo SAS')
        ->assertSeeText('Proceso jurídico')
        ->assertSeeText('Graph did not send the mail: HTTP 429.')
        ->assertSeeText('20/10/2026');
});

it('filters the report by result', function () {
    $this->actingAs($this->analyst)->get("/cobro/lotes/{$this->run->id}?estado=rebotado")->assertOk()
        ->assertSeeText('Distribuciones Andinas SAS')
        ->assertDontSeeText('Ferretería El Tornillo SAS');
});

it('warns while the run is still sending', function () {
    $this->run->update(['status' => RunStatus::Sending, 'finished_at' => null]);

    $this->actingAs($this->analyst)->get("/cobro/lotes/{$this->run->id}")->assertOk()
        ->assertSeeText('Todavía se están enviando correos.');
});
