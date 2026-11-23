<?php

use App\Models\User;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Database\Seeders\SoportesPermissionSeeder;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Modules\Soportes\Support\SoportesUsers;

beforeEach(function () {
    $this->seed(SoportesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
});

/**
 * A client of advisor $advisorCode, known to the portfolio fake.
 */
function soportesInboxClient(string $name, int $advisorCode = 10, CompanyCode $company = CompanyCode::SGX): ClientSummary
{
    $summary = Make::client(company: $company, name: $name, advisor: Make::advisor(company: $company, code: $advisorCode));
    test()->fakes->cartera->addClient(Make::clientDetail($summary));

    return $summary;
}

/**
 * A saved receipt of $client uploaded by $uploader; $state is a factory
 * state ("applied", "rejected") or null for a pending one.
 *
 * @param  array<string, mixed>  $attributes
 */
function soportesInboxReceipt(ClientSummary $client, User $uploader, ?string $state = null, array $attributes = []): Receipt
{
    $factory = Receipt::factory()->forClient($client)->uploadedBy($uploader);

    return ($state === null ? $factory : $factory->{$state}())->create($attributes);
}

/**
 * The "Soportes de pago" menu entry $user sees, or null.
 */
function soportesMenuEntry(User $user): ?NavItem
{
    test()->actingAs($user);

    return collect(app(NavRegistry::class)->visibleTo($user))->firstWhere('key', 'soportes.bandeja');
}

it('serves the inbox at /soportes', function () {
    expect(route('soportes.index', absolute: false))->toBe('/soportes');
});

describe('access', function () {
    it('sends a guest to the login', function () {
        $this->get('/soportes')->assertRedirect('/login');
    });

    it('keeps out whoever has no soportes.view', function (?Role $role) {
        $this->actingAs(SoportesUsers::create($role))->get('/soportes')->assertForbidden();
    })->with([[Role::Treasury], [Role::Admin], [Role::SalesManager], [null]]);

    it('lets the superadmin open the inbox, without the upload button', function () {
        soportesInboxReceipt(soportesInboxClient('Ferretería El Tornillo SAS'), SoportesUsers::advisor());

        $this->actingAs(SoportesUsers::create(Role::SuperAdmin))->get('/soportes')
            ->assertOk()
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertDontSee(route('soportes.create'));
    });

    it('gives the superadmin the general title, not the advisor\'s', function () {
        $this->actingAs(SoportesUsers::create(Role::SuperAdmin))->get('/soportes')
            ->assertOk()
            ->assertSee('Los soportes que cargan los asesores y cartera.')
            ->assertDontSee('Mis soportes')
            ->assertDontSee('Los soportes de tus clientes y los que cargaste.');
    });
});

describe('the advisor', function () {
    it('sees every receipt of their own clients and the ones they uploaded, not another advisor\'s', function () {
        $advisor = SoportesUsers::advisor();
        $analyst = SoportesUsers::analyst();

        soportesInboxReceipt(soportesInboxClient('Cliente Propio SAS', 10), $analyst);
        soportesInboxReceipt(soportesInboxClient('Cliente Reasignado SAS', 20), $advisor);
        soportesInboxReceipt(soportesInboxClient('Cliente De Otra Asesora SAS', 20), $analyst);
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs($advisor)->get('/soportes')
            ->assertOk()
            ->assertSee('Mis soportes')
            ->assertSee('Cliente Propio SAS')
            ->assertSee('Cliente Reasignado SAS')
            ->assertDontSee('Cliente De Otra Asesora SAS')
            ->assertSee(route('soportes.create'));
    });

    it('sees why a receipt was rejected', function () {
        $advisor = SoportesUsers::advisor();
        soportesInboxReceipt(soportesInboxClient('Cliente Propio SAS', 10), $advisor, 'rejected', ['rejection_reason' => 'El comprobante está ilegible.']);
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs($advisor)->get('/soportes?estado=rechazado')
            ->assertOk()
            ->assertSee('Cliente Propio SAS')
            ->assertSee('El comprobante está ilegible.');
    });

    it('sees nothing when no receipt is theirs', function () {
        soportesInboxReceipt(soportesInboxClient('Cliente De Otra Asesora SAS', 20), SoportesUsers::analyst());
        $this->fakes->cartera->restrictToAdvisor(10);

        $this->actingAs(SoportesUsers::advisor())->get('/soportes')
            ->assertOk()
            ->assertDontSee('Cliente De Otra Asesora SAS')
            ->assertSee('No tienes soportes en esta lista');
    });
});

describe('the portfolio team', function () {
    it('sees the receipts of the companies of the switcher', function () {
        $advisor = SoportesUsers::advisor();
        soportesInboxReceipt(soportesInboxClient('Cliente Segurex SAS'), $advisor);
        soportesInboxReceipt(soportesInboxClient('Cliente Derivados SAS', company: CompanyCode::SDC), $advisor);
        $analyst = SoportesUsers::analyst();

        $this->actingAs($analyst)->get('/soportes')
            ->assertOk()
            ->assertSee('Soportes de pago')
            ->assertSee('Cliente Segurex SAS')
            ->assertDontSee('Cliente Derivados SAS');

        $this->fakes->currentCompany->switchTo(null);

        $this->actingAs($analyst)->get('/soportes')
            ->assertSee('Cliente Segurex SAS')
            ->assertSee('Cliente Derivados SAS')
            ->assertViewHas('isGroup', true);

        $this->fakes->currentCompany->switchTo(CompanyCode::SDC);

        $this->actingAs($analyst)->get('/soportes')
            ->assertDontSee('Cliente Segurex SAS')
            ->assertSee('Cliente Derivados SAS');
    });

    it('splits the receipts in pending, applied and rejected tabs with their counts', function () {
        $advisor = SoportesUsers::advisor();
        soportesInboxReceipt(soportesInboxClient('Pendiente Uno SAS'), $advisor);
        soportesInboxReceipt(soportesInboxClient('Pendiente Dos SAS'), $advisor);
        soportesInboxReceipt(soportesInboxClient('Aplicado SAS'), $advisor, 'applied');
        soportesInboxReceipt(soportesInboxClient('Rechazado SAS'), $advisor, 'rejected');
        $analyst = SoportesUsers::analyst();

        $this->actingAs($analyst)->get('/soportes')
            ->assertOk()
            ->assertViewHas('counts', ['pendiente' => 2, 'aplicado' => 1, 'rechazado' => 1])
            ->assertSee('Pendiente Uno SAS')
            ->assertSee('Pendiente Dos SAS')
            ->assertDontSee('Aplicado SAS')
            ->assertSee('/soportes?estado=aplicado', false);

        $this->actingAs($analyst)->get('/soportes?estado=aplicado')
            ->assertSee('Aplicado SAS')
            ->assertDontSee('Pendiente Uno SAS')
            ->assertDontSee('Rechazado SAS');

        $this->actingAs($analyst)->get('/soportes?estado=desconocido')->assertSee('Pendiente Uno SAS');
    });

    it('filters by client name or code and by payment date', function () {
        $advisor = SoportesUsers::advisor();
        $tornillo = soportesInboxClient('Ferretería El Tornillo SAS');
        soportesInboxReceipt($tornillo, $advisor, attributes: ['paid_at' => '2026-11-02']);
        soportesInboxReceipt(soportesInboxClient('Distribuciones La Rueda SAS'), $advisor, attributes: ['paid_at' => '2026-11-15']);
        $analyst = SoportesUsers::analyst();

        $this->actingAs($analyst)->get('/soportes?buscar=rueda')
            ->assertSee('Distribuciones La Rueda SAS')
            ->assertDontSee('Ferretería El Tornillo SAS')
            ->assertViewHas('counts', ['pendiente' => 1, 'aplicado' => 0, 'rechazado' => 0]);

        $this->actingAs($analyst)->get('/soportes?buscar='.$tornillo->code)
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertDontSee('Distribuciones La Rueda SAS');

        $this->actingAs($analyst)->get('/soportes?desde=2026-11-10&hasta=2026-11-20')
            ->assertSee('Distribuciones La Rueda SAS')
            ->assertDontSee('Ferretería El Tornillo SAS');

        $this->actingAs($analyst)->get('/soportes?hasta=2026-11-05&desde=no-es-fecha')
            ->assertSee('Ferretería El Tornillo SAS')
            ->assertDontSee('Distribuciones La Rueda SAS');
    });

    it('pages the list', function () {
        $advisor = SoportesUsers::advisor();
        $client = soportesInboxClient('Ferretería El Tornillo SAS');
        Receipt::factory()->count(26)->forClient($client)->uploadedBy($advisor)->create();

        $response = $this->actingAs(SoportesUsers::analyst())->get('/soportes')->assertOk();

        expect($response->viewData('receipts')->count())->toBe(25)
            ->and($response->viewData('receipts')->total())->toBe(26);
    });
});

describe('the menu', function () {
    it('shows "Soportes de pago" to whoever has soportes.view', function (Role $role) {
        expect(soportesMenuEntry(SoportesUsers::create($role))?->label)->toBe('Soportes de pago');
    })->with([[Role::SalesAdvisor], [Role::PortfolioAnalyst], [Role::PortfolioManager], [Role::SuperAdmin]]);

    it('hides it from everyone else', function (?Role $role) {
        expect(soportesMenuEntry(SoportesUsers::create($role)))->toBeNull();
    })->with([[Role::Treasury], [Role::Admin], [null]]);

    it('counts the pending receipts of the companies in view for the portfolio team', function () {
        $advisor = SoportesUsers::advisor();
        soportesInboxReceipt(soportesInboxClient('Uno SAS'), $advisor);
        soportesInboxReceipt(soportesInboxClient('Dos SAS'), $advisor);
        soportesInboxReceipt(soportesInboxClient('Tres SAS'), $advisor, 'applied');
        soportesInboxReceipt(soportesInboxClient('Cuatro SAS', company: CompanyCode::SDC), $advisor);

        $entry = soportesMenuEntry(SoportesUsers::analyst());

        expect($entry?->badgeCount())->toBe(2);

        $this->fakes->currentCompany->switchTo(null);

        expect($entry?->badgeCount())->toBe(3);
    });

    it('has no badge for the advisor, nor when nothing is pending', function () {
        $advisor = SoportesUsers::advisor();
        soportesInboxReceipt(soportesInboxClient('Uno SAS', 10), $advisor);
        $this->fakes->cartera->restrictToAdvisor(10);

        expect(soportesMenuEntry($advisor)?->badgeCount())->toBeNull();

        Receipt::query()->update(['status' => 'aplicado']);

        expect(soportesMenuEntry(SoportesUsers::analyst())?->badgeCount())->toBeNull();
    });
});
