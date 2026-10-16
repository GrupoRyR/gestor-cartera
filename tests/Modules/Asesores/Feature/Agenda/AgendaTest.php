<?php

use App\Modules\Asesores\Application\Agenda\AgendaReader;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;
use Tests\Modules\Core\Support\CommitmentRecords;
use Tests\Modules\Core\Support\CoreUsers;

/*
 * Today is 2026-10-16. Ana (SGX-12) has: broken 4M (5 Oct) and a pending one
 * of 5M whose day passed yesterday, 1M today, 500k tomorrow, 2M on the 20th,
 * 3M on 30 November, and a fulfilled and a cancelled one that must not show.
 * Bruno has 900k today. Gloria's team has 700k on the 25th (SGX-30) and 1,2M
 * on the 18th (SDC-50).
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $agro = $this->world->clients['agro'];
    $ana = $this->world->ana;

    CommitmentRecords::commitment($agro, $ana, '2026-10-05', '4000000.00', ['status' => CommitmentStatus::Broken, 'broken_at' => '2026-10-06 00:15:00']);
    CommitmentRecords::commitment($agro, $ana, '2026-10-15', '5000000.00');
    CommitmentRecords::commitment($agro, $ana, '2026-10-16', '1000000.00');
    CommitmentRecords::commitment($this->world->clients['ferreteria'], $ana, '2026-10-17', '500000.00');
    CommitmentRecords::commitment($agro, $ana, '2026-10-20', '2000000.00');
    CommitmentRecords::commitment($agro, $ana, '2026-11-30', '3000000.00');
    CommitmentRecords::commitment($agro, $ana, '2026-10-16', '111000.00', ['status' => CommitmentStatus::Fulfilled, 'fulfilled_at' => '2026-10-15 10:00:00']);
    CommitmentRecords::commitment($agro, $ana, '2026-10-16', '222000.00', ['status' => CommitmentStatus::Cancelled, 'cancelled_at' => '2026-10-15 10:00:00', 'cancellation_reason' => 'Por error']);

    CommitmentRecords::commitment($this->world->clients['bodega'], $this->world->bruno, '2026-10-16', '900000.00');
    CommitmentRecords::commitment($this->world->clients['comercial'], $this->world->gloria, '2026-10-25', '700000.00');
    CommitmentRecords::commitment($this->world->clients['granos'], $this->world->gloria, '2026-10-18', '1200000.00');
});

it('groups the open commitments of the advisor by date', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.agenda'))->assertOk();

    // Broken first, oldest first; a pending one whose day passed counts as broken too.
    expect(Page::groupRows($response, 'broken', 3))->toBe([Money::format(4_000_000), Money::format(5_000_000)])
        ->and(Page::groupRows($response, 'today', 3))->toBe([Money::format(1_000_000)])
        ->and(Page::groupRows($response, 'week', 3))->toBe([Money::format(500_000), Money::format(2_000_000)])
        ->and(Page::groupRows($response, 'later', 3))->toBe([Money::format(3_000_000)]);
});

it('names the client of each commitment and how far its day is', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));

    expect(Page::groupRows($response, 'week', 1))->toBe(['Ferretería Franco', 'Agro Arango'])
        ->and(Page::groupRows($response, 'broken', 2))->toBe(['05/10/2026 hace 11 días', '15/10/2026 hace 1 día'])
        ->and(Page::groupRows($response, 'today', 2))->toBe(['16/10/2026 hoy'])
        ->and(Page::groupRows($response, 'week', 2))->toBe(['17/10/2026 mañana', '20/10/2026 en 4 días']);
});

it('leaves out the fulfilled and cancelled commitments', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));

    $response->assertDontSeeText(Money::format(111_000))->assertDontSeeText(Money::format(222_000));
});

it('sums up what is broken, due today and due this week', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));

    expect(Page::stat($response, 'Incumplidos'))->toBe('2')
        ->and(Page::stat($response, 'Vencen hoy'))->toBe('1')
        ->and(Page::stat($response, 'Próximos 7 días'))->toBe('2');
});

it('never shows the commitments of another advisor', function () {
    foreach ([['asesor' => 'SGX-20'], []] as $query) {
        $response = $this->actingAs($this->world->ana)->get(route('asesores.agenda', $query))->assertOk();

        $response->assertDontSeeText('Bodega Bedoya')->assertDontSeeText(Money::format(900_000))
            ->assertDontSeeText('Comercial Castaño')->assertDontSeeText('Granos Giraldo');
    }

    // Asking for Bruno leaves Ana with nothing, not with Bruno's.
    $this->actingAs($this->world->ana)->get(route('asesores.agenda', ['asesor' => 'SGX-20']))
        ->assertSeeText('No tienes compromisos abiertos');
});

it('shows each advisor only their own agenda', function () {
    $bruno = $this->actingAs($this->world->bruno)->get(route('asesores.agenda'))->assertOk();

    expect(Page::groupRows($bruno, 'today', 1))->toBe(['Bodega Bedoya'])
        ->and(Page::html($bruno)->count('//*[@data-group="broken"]'))->toBe(0)
        ->and(Page::html($bruno)->count('//*[@data-group="week"]'))->toBe(0);
});

it('shows a sales manager the commitments of the team, and only the team', function () {
    $response = $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.agenda'))->assertOk();

    expect(Page::groupRows($response, 'week', 1))->toBe(['Granos Giraldo'])
        ->and(Page::groupRows($response, 'later', 1))->toBe(['Comercial Castaño']);
    $response->assertDontSeeText('Agro Arango')->assertDontSeeText('Bodega Bedoya');
});

it('lets a sales manager narrow the agenda to one advisor of the team', function () {
    $response = $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.agenda', ['asesor' => 'SGX-30']))->assertOk();

    expect(Page::groupRows($response, 'later', 1))->toBe(['Comercial Castaño'])
        ->and(Page::html($response)->count('//*[@data-group="week"]'))->toBe(0);
});

it('offers the advisor filter only to who can look at several advisors', function () {
    $gloria = $this->actingAs($this->world->gloria)->withSession(['core.company' => 'group'])->get(route('asesores.agenda'));
    $ana = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));

    expect(Page::html($gloria)->count('//form[@data-part="advisor-filter"]'))->toBe(1)
        ->and(Page::html($ana)->count('//form[@data-part="advisor-filter"]'))->toBe(0);
});

it('links each row to the client file and to the commitment', function () {
    $response = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));
    $hrefs = array_map(fn ($link) => $link->getAttribute('href'), Page::html($response)->all('//*[@data-group="today"]//a'));

    expect($hrefs)->toHaveCount(2)
        ->and($hrefs[0])->toBe(route('asesores.clientes.show', $this->world->clients['agro']->id))
        ->and($hrefs[1])->toStartWith(url('/asesores/compromisos/'));
});

it('labels the link as managing for the advisor and as detail for a manager', function () {
    $ana = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));
    $gloria = $this->actingAs($this->world->gloria)->get(route('asesores.agenda'));

    $ana->assertSeeText('Ver y gestionar');
    $gloria->assertSeeText('Ver detalle')->assertDontSeeText('Ver y gestionar');
});

it('shows the number of broken commitments on the menu', function () {
    $badge = fn ($response) => Page::html($response)->all('//nav[@id="menu-principal"]//a[contains(@href,"/asesores/agenda")]//*[@data-part="badge"]');

    $ana = $this->actingAs($this->world->ana)->get(route('asesores.agenda'));
    $bruno = $this->actingAs($this->world->bruno)->get(route('asesores.agenda'));

    // Only the one Core already marked as broken: the pending one of yesterday waits for the nightly task.
    expect(array_map(fn ($node) => trim($node->textContent), $badge($ana)))->toBe(['1'])
        ->and($badge($bruno))->toBe([]);
});

it('says what to do when there is nothing open', function () {
    $this->actingAs(CoreUsers::create(Role::SalesAdvisor, CompanyCode::SGX))
        ->get(route('asesores.agenda'))
        ->assertOk()
        ->assertSeeText('No tienes compromisos abiertos')
        ->assertSeeText('regístrala desde su ficha');
});

it('warns when there are more open commitments than the agenda reads', function () {
    $agro = $this->world->clients['agro'];

    CommitmentRecord::factory()->count(AgendaReader::LIMIT)->create([
        'client_id' => $agro->id, 'created_by' => $this->world->ana->id, 'due_date' => '2026-12-31',
    ]);

    $this->actingAs($this->world->ana)->get(route('asesores.agenda'))
        ->assertOk()
        ->assertSeeText('Hay más compromisos abiertos de los que caben aquí');
});

it('does not warn when everything fits', function () {
    $this->actingAs($this->world->ana)->get(route('asesores.agenda'))->assertDontSeeText('Hay más compromisos abiertos');
});

it('is closed to guests and to the roles that have their own screens', function () {
    $this->get(route('asesores.agenda'))->assertRedirect(route('core.login'));
    $this->actingAs($this->world->mariaPaula)->get(route('asesores.agenda'))->assertForbidden();
});

it('ignores a page number in the URL: the agenda and the client file read their own list', function () {
    $agenda = $this->actingAs($this->world->ana)->get(route('asesores.agenda', ['page' => 2]));
    $file = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', [$this->world->clients['agro']->id, 'page' => 3]));

    expect(Page::groupRows($agenda, 'today', 1))->toBe(['Agro Arango'])
        ->and(Page::html($file)->count('//ul[@data-part="commitments"]/li'))->toBeGreaterThan(0);
});
