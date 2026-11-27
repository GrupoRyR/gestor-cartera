<?php

use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;
use Tests\Feature\Design\Support\Html;
use Tests\Modules\Cartera\Support\CarteraUsers;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));

    $this->fakes = ContractFakes::install($this->app);
    $this->client = Make::client(name: 'Ferretería El Tornillo SAS');
    $this->invoice = Make::invoice(
        clientId: $this->client->id,
        documentNumber: 'FV-123',
        dueAt: CarbonImmutable::parse('2026-10-15'),
        balance: 1_234_567.0,
    );
    $this->fakes->cartera->addClient(Make::clientDetail($this->client, contacts: [
        Make::contact(name: 'Laura Martínez', phone: '6015550123', isPrimary: true),
        Make::contact(name: 'Juan Pérez', phone: '3001234567'),
    ]), $this->invoice);

    $this->file = "/cartera/clientes/{$this->client->id}";
    $this->url = "{$this->file}/llamadas";
});

/**
 * A valid form of the call modal, with the fields each test changes.
 *
 * @param  array<string, mixed>  $fields
 * @return array{llamada: array<string, mixed>}
 */
function callForm(array $fields = []): array
{
    return ['llamada' => $fields + [
        'ocurrio' => '2026-11-20T09:30',
        'canal' => 'llamada',
        'numero' => '3001234567',
        'telefono' => '',
        'persona' => 'Juan Pérez',
        'contacto' => '',
        'resultado' => 'contesto',
        'notas' => 'Pagará la otra semana.',
        'acuerdo' => 'ninguno',
        'seguimiento' => '',
    ]];
}

/**
 * The client file page as $user sees it.
 */
function callFilePage(mixed $user, string $query = ''): string
{
    return (string) test()->actingAs($user)->get(test()->file.$query)->assertOk()->getContent();
}

function callModalOpens(string $html): bool
{
    return preg_match('/<dialog[^>]*id="gestion-llamada"[^>]*data-modal-autoopen/s', $html) === 1;
}

it('serves the call form at /cartera/clientes/{client}/llamadas', function () {
    expect(route('cartera.llamadas.store', 12, absolute: false))->toBe('/cartera/clientes/12/llamadas');
});

it('sends guests to the sign-in page', function () {
    $this->post($this->url, callForm())->assertRedirect('/login');
});

describe('the modal in the client file', function () {
    it('is there for the portfolio team, posting to the call log', function (Role $role) {
        $html = callFilePage(CarteraUsers::create($role));
        $page = Html::parse($html);

        expect($html)->toContain('data-modal-open="gestion-llamada"')
            ->and($page->one('//dialog[@id="gestion-llamada"]//form')->getAttribute('action'))->toBe(route('cartera.llamadas.store', $this->client->id))
            ->and($page->one('//input[@name="llamada[ocurrio]"]')->getAttribute('value'))->toBe('2026-11-20T10:00')
            ->and($page->one('//input[@name="llamada[ocurrio]"]')->getAttribute('type'))->toBe('datetime-local')
            ->and($page->count('//select[@name="llamada[canal]"]/option'))->toBe(count(CallChannel::cases()))
            ->and($page->count('//select[@name="llamada[resultado]"]/option[@value!=""]'))->toBe(count(CallOutcome::cases()))
            ->and($page->one('//select[@name="llamada[numero]"]/option[@selected]')->getAttribute('value'))->toBe('6015550123')
            ->and($page->text('//select[@name="llamada[numero]"]'))->toContain('3001234567')->toContain('Otro número')
            ->and($page->text('//select[@name="llamada[persona]"]'))->toContain('Juan Pérez')->toContain('Otra persona')
            ->and($page->count('//input[@name="llamada[telefono]"]'))->toBe(1)
            ->and($page->count('//input[@name="llamada[contacto]"]'))->toBe(1)
            ->and($page->count('//textarea[@name="llamada[notas]"]'))->toBe(1)
            ->and($page->one('//input[@name="llamada[seguimiento]"]')->getAttribute('type'))->toBe('date')
            ->and($html)->toContain('Pidió volver a llamar')
            ->and($page->one('//input[@name="llamada[facturas]['.$this->invoice->id.']"]')->getAttribute('value'))->toBe((string) $this->invoice->id)
            ->and($html)->toContain('Factura FV-123, vence 15/10/2026, saldo')
            ->and(callModalOpens($html))->toBeFalse();
    })->with([Role::PortfolioAnalyst, Role::PortfolioManager]);

    it('is not there for those who may only look', function () {
        $html = callFilePage(CarteraUsers::viewer());

        expect($html)->not->toContain('data-modal-open="gestion-llamada"')
            ->and($html)->not->toContain('id="gestion-llamada"')
            ->and($html)->not->toContain(route('cartera.llamadas.store', $this->client->id));
    });

    it('offers the payment commitment only to who writes the commitments of the company', function (string $who, CompanyCode $company, bool $offered) {
        $client = $this->client;

        if ($company === CompanyCode::SDC) {
            $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
            $client = Make::client(company: CompanyCode::SDC, name: 'Agro Arango SAS');
            $this->fakes->cartera->addClient(Make::clientDetail($client));
        }

        $user = $who === 'analyst' ? CarteraUsers::analyst() : CarteraUsers::manager();
        $html = (string) $this->actingAs($user)->get("/cartera/clientes/{$client->id}")->assertOk()->getContent();
        $agreements = Html::parse($html)->all('//select[@name="llamada[acuerdo]"]/option');

        expect(array_map(fn (DOMElement $option): string => $option->getAttribute('value'), $agreements))
            ->toBe($offered ? ['ninguno', 'compromiso_pago', 'envio_soporte'] : ['ninguno', 'envio_soporte']);
    })->with([
        'the SDC analyst' => ['analyst', CompanyCode::SDC, true],
        'the SGX analyst' => ['analyst', CompanyCode::SGX, false],
        'the SDC manager' => ['manager', CompanyCode::SDC, false],
    ]);

    it('opens on arrival from the follow-up notice', function () {
        expect(callModalOpens(callFilePage(CarteraUsers::analyst(), '?registrar-llamada=1')))->toBeTrue();
    });

    it('asks for the number when no contact has a phone', function () {
        $client = Make::client(name: 'Sin Teléfono SAS');
        $this->fakes->cartera->addClient(Make::clientDetail($client, contacts: [Make::contact(name: 'Ana', phone: null)]));

        $html = (string) $this->actingAs(CarteraUsers::analyst())->get("/cartera/clientes/{$client->id}")->assertOk()->getContent();
        $page = Html::parse($html);

        expect($page->count('//select[@name="llamada[numero]"]'))->toBe(0)
            ->and($page->one('//input[@name="llamada[telefono]"]')->hasAttribute('required'))->toBeTrue();
    });
});

describe('posting a call', function () {
    it('stores the call and its invoices and goes back to the file', function (Role $role) {
        $this->actingAs(CarteraUsers::create($role))
            ->post($this->url, callForm([
                'canal' => 'llamada_whatsapp',
                'acuerdo' => 'envio_soporte',
                'facturas' => [$this->invoice->id => (string) $this->invoice->id],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Llamada registrada.');

        $call = CallRecord::query()->with('invoices')->sole();

        expect($call->client_id)->toBe($this->client->id)
            ->and($call->channel)->toBe(CallChannel::WhatsappCall)
            ->and($call->phone)->toBe('3001234567')
            ->and($call->contact_name)->toBe('Juan Pérez')
            ->and($call->outcome)->toBe(CallOutcome::Answered)
            ->and($call->agreement)->toBe(CallAgreement::SendReceipt)
            ->and($call->notes)->toBe('Pagará la otra semana.')
            ->and($call->occurred_at->equalTo(CarbonImmutable::parse('2026-11-20 09:30:00', 'America/Bogota')))->toBeTrue()
            ->and($call->invoices->sole()->document_number)->toBe('FV-123');

        expect($this->fakes->gestiones->logged())->toHaveCount(1);
    })->with([Role::PortfolioAnalyst, Role::PortfolioManager]);

    it('takes the typed number and person over the lists', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, callForm(['telefono' => ' 3109998877 ', 'contacto' => 'Pedro, el contador']))
            ->assertSessionHasNoErrors();

        $call = CallRecord::query()->sole();

        expect($call->phone)->toBe('3109998877')->and($call->contact_name)->toBe('Pedro, el contador');
    });

    it('lets the browser take as long a number and name as the server does', function () {
        $html = callFilePage(CarteraUsers::analyst());

        expect($html)->toMatch('/<input[^>]*name="llamada\[telefono\]"[^>]*maxlength="255"/s')
            ->toMatch('/<input[^>]*name="llamada\[contacto\]"[^>]*maxlength="150"/s');
    });

    it('takes a contact phone as long as SAP keeps it', function () {
        // Core keeps a contact's phone in a string(255), and SAP often puts several numbers in it.
        $phones = '6015550123 ext 104 - 3001234567 - 3109876543 (solo en horario de oficina)';
        $client = Make::client(name: 'Distribuidora La 14');
        $this->fakes->cartera->addClient(Make::clientDetail($client, contacts: [Make::contact(name: 'Tesorería', phone: $phones)]));

        $this->actingAs(CarteraUsers::analyst())
            ->post("/cartera/clientes/{$client->id}/llamadas", callForm(['numero' => $phones, 'persona' => 'Tesorería']))
            ->assertSessionHasNoErrors();

        expect(CallRecord::query()->sole()->phone)->toBe($phones);
    });

    it('may go without a contact, and keeps the day to call back', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, callForm(['persona' => '', 'resultado' => 'volver_a_llamar', 'seguimiento' => '2026-11-24', 'notas' => '']))
            ->assertSessionHasNoErrors();

        $call = CallRecord::query()->sole();

        expect($call->contact_name)->toBeNull()
            ->and($call->notes)->toBeNull()
            ->and($call->follow_up_on?->format('Y-m-d'))->toBe('2026-11-24');
    });

    it('lets the SDC analyst agree a payment commitment', function () {
        $this->fakes->currentCompany->switchTo(CompanyCode::SDC);
        $client = Make::client(company: CompanyCode::SDC, name: 'Agro Arango SAS');
        $this->fakes->cartera->addClient(Make::clientDetail($client));

        $response = $this->actingAs(CarteraUsers::analyst())
            ->post("/cartera/clientes/{$client->id}/llamadas", callForm(['acuerdo' => 'compromiso_pago']));
        $call = CallRecord::query()->sole();

        // The commitment form comes next (CallCommitmentTest).
        $response->assertRedirect("/cartera/clientes/{$client->id}?nuevo-compromiso=1&llamada={$call->id}");
        expect($call->agreement)->toBe(CallAgreement::PaymentCommitment);
    });

    it('refuses a payment commitment to who does not write them', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, callForm(['acuerdo' => 'compromiso_pago']))
            ->assertForbidden();

        expect(CallRecord::query()->count())->toBe(0);
    });

    it('is for the portfolio team only', function (?Role $role) {
        $user = $role === null ? CarteraUsers::viewer() : CarteraUsers::create($role);

        $this->actingAs($user)->post($this->url, callForm())->assertForbidden();

        expect(CallRecord::query()->count())->toBe(0);
        $this->fakes->gestiones->assertNothingLogged();
    })->with([
        'advisor' => [Role::SalesAdvisor],
        'sales manager' => [Role::SalesManager],
        'treasury' => [Role::Treasury],
        'viewer' => [null],
    ]);

    it('answers 404 for a client that does not exist or is out of reach', function () {
        $this->actingAs(CarteraUsers::analyst())->post('/cartera/clientes/987654/llamadas', callForm())->assertNotFound();

        $this->fakes->cartera->restrictToAdvisor(999);

        $this->actingAs(CarteraUsers::analyst())->post($this->url, callForm())->assertNotFound();

        expect(CallRecord::query()->count())->toBe(0);
    });
});

describe('a call that is refused', function () {
    it('comes back to the open modal with what was typed and the error under its field', function (array $fields, string $field, string $message) {
        $user = CarteraUsers::analyst();
        $form = callForm($fields + ['notas' => 'Lo que alcancé a escribir']);

        $this->actingAs($user)
            ->from('/cartera/kanban')
            ->post($this->url, $form)
            ->assertRedirect($this->file)
            ->assertSessionHasErrors(["llamada.{$field}" => $message]);

        $html = (string) $this->actingAs($user)->followingRedirects()->post($this->url, $form)->assertOk()->getContent();

        expect(callModalOpens($html))->toBeTrue()
            ->and($html)->toContain('Lo que alcancé a escribir')
            ->and($html)->toContain(e($message))
            ->and(CallRecord::query()->count())->toBe(0);
    })->with([
        'a callback without a day' => [['resultado' => 'volver_a_llamar'], 'seguimiento', 'Elige el día en que vas a volver a llamar.'],
        'an agreement without an answer' => [['resultado' => 'no_contesto', 'acuerdo' => 'envio_soporte'], 'acuerdo', 'Registra un acuerdo solo si el cliente contestó.'],
        'a call in the future' => [['ocurrio' => '2026-11-21T08:00'], 'ocurrio', 'La llamada no puede tener una fecha futura.'],
        'an invoice that is not open' => [['facturas' => ['999999' => '999999']], 'facturas', 'Alguna de las facturas ya no está abierta. Vuelve a cargar la ficha.'],
    ]);

    it('keeps the chosen invoices checked', function () {
        $html = (string) $this->actingAs(CarteraUsers::analyst())
            ->followingRedirects()
            ->post($this->url, callForm(['resultado' => 'volver_a_llamar', 'facturas' => [$this->invoice->id => (string) $this->invoice->id]]))
            ->getContent();

        $page = Html::parse($html);

        expect($page->one('//input[@name="llamada[facturas]['.$this->invoice->id.']"]')->hasAttribute('checked'))->toBeTrue();
    });

    it('checks the shape of the fields', function (array $fields, string $field) {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, callForm($fields))
            ->assertRedirect($this->file)
            ->assertSessionHasErrors("llamada.{$field}");

        expect(CallRecord::query()->count())->toBe(0);
    })->with([
        'unknown channel' => [['canal' => 'paloma'], 'canal'],
        'missing outcome' => [['resultado' => ''], 'resultado'],
        'unknown agreement' => [['acuerdo' => 'regalo'], 'acuerdo'],
        'no number at all' => [['numero' => '', 'telefono' => ''], 'telefono'],
        'too long a number' => [['telefono' => str_repeat('1', 256)], 'telefono'],
        'too long a contact' => [['contacto' => str_repeat('a', 151)], 'contacto'],
        'not a date-time' => [['ocurrio' => 'ayer'], 'ocurrio'],
        'not a date' => [['seguimiento' => '24/11/2026'], 'seguimiento'],
        'an invoice that is not an id' => [['facturas' => ['x' => 'abc']], 'facturas.x'],
        'too long notes' => [['notas' => str_repeat('a', 2001)], 'notas'],
    ]);
});

describe('the old simple call', function () {
    it('is no longer a kind of log entry', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/gestiones", ['tipo' => 'llamada', 'llamada' => ['resultado' => 'promete_pago']])
            ->assertSessionHasErrors('tipo');

        $this->fakes->gestiones->assertNothingLogged();
    });

    it('leaves notes and discounts as they were', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/gestiones", ['tipo' => 'nota', 'nota' => ['detalle' => 'Hola']])
            ->assertSessionHas('status', 'Nota registrada.');

        $this->actingAs(CarteraUsers::analyst())
            ->post("{$this->file}/gestiones", ['tipo' => 'descuento', 'descuento' => ['valor' => '1000']])
            ->assertSessionHas('status', 'Descuento registrado.');

        expect($this->fakes->gestiones->logged())->toHaveCount(2);
    });
});
