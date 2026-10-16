<?php

use App\Modules\Core\Contracts\Data\Activity;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\Role;
use App\Support\Formatting\Money;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Cartera\Support\CarteraUsers;
use Tests\Modules\Cartera\Support\CarteraWorld;

beforeEach(function () {
    $this->fakes = ContractFakes::install($this->app);
    $this->client = CarteraWorld::client($this->fakes, name: 'Ferretería El Tornillo SAS', overdue: 2_000_000);
    $this->url = "/cartera/clientes/{$this->client->id}/gestiones";
    $this->file = "/cartera/clientes/{$this->client->id}";
});

/**
 * The only entry the fake log received.
 */
function loggedEntry(): Activity
{
    $logged = test()->fakes->gestiones->logged();

    expect($logged)->toHaveCount(1);

    return $logged[0];
}

it('serves the log form at /cartera/clientes/{client}/gestiones', function () {
    expect(route('cartera.gestiones.store', 12, absolute: false))->toBe('/cartera/clientes/12/gestiones');
});

it('sends guests to the sign-in page', function () {
    $this->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => 'Hola']])->assertRedirect('/login');
});

it('is for those who manage the portfolio only', function (?Role $role) {
    $this->actingAs(CarteraUsers::create($role))
        ->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => 'Hola']])
        ->assertForbidden();

    $this->fakes->gestiones->assertNothingLogged();
})->with([
    'advisor' => [Role::SalesAdvisor],
    'sales manager' => [Role::SalesManager],
    'treasury' => [Role::Treasury],
    'no role' => [null],
]);

it('refuses those who may only look', function () {
    $this->actingAs(CarteraUsers::viewer())->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => 'Hola']])->assertForbidden();

    $this->fakes->gestiones->assertNothingLogged();
});

describe('a call', function () {
    it('is logged with its result and what was agreed', function (Role $role) {
        $this->actingAs(CarteraUsers::create($role))
            ->post($this->url, ['tipo' => 'llamada', 'llamada' => ['resultado' => 'promete_pago', 'detalle' => 'Habló con Laura; paga el viernes.']])
            ->assertRedirect($this->file)
            ->assertSessionHas('status', 'Llamada registrada.');

        $entry = loggedEntry();

        expect($entry->type)->toBe(ActivityType::Call)
            ->and($entry->clientId)->toBe($this->client->id)
            ->and($entry->result)->toBe('Contactado — promete pago')
            ->and($entry->summary)->toBe('Llamada: Contactado — promete pago')
            ->and($entry->details)->toBe('Habló con Laura; paga el viernes.');
    })->with([Role::PortfolioAnalyst, Role::PortfolioManager]);

    it('may go without details', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, ['tipo' => 'llamada', 'llamada' => ['resultado' => 'no_contesta', 'detalle' => '   ']])
            ->assertSessionHasNoErrors();

        expect(loggedEntry()->details)->toBeNull()->and(loggedEntry()->result)->toBe('No contesta');
    });

    it('needs one of the known results', function (mixed $result) {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, ['tipo' => 'llamada', 'llamada' => ['resultado' => $result]])
            ->assertSessionHasErrors('llamada.resultado');

        $this->fakes->gestiones->assertNothingLogged();
    })->with(['missing' => [null], 'unknown' => ['se_lo_comio_el_perro'], 'empty' => ['']]);
});

describe('a note', function () {
    it('is logged with its text', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => "Pidió el estado de cuenta.\nSe lo enviamos mañana."]])
            ->assertSessionHas('status', 'Nota registrada.');

        $entry = loggedEntry();

        expect($entry->type)->toBe(ActivityType::Note)
            ->and($entry->summary)->toBe('Pidió el estado de cuenta.')
            ->and($entry->details)->toBe("Pidió el estado de cuenta.\nSe lo enviamos mañana.")
            ->and($entry->result)->toBeNull();
    });

    it('keeps a short one-line text only in the summary', function () {
        $this->actingAs(CarteraUsers::analyst())->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => 'Cliente en vacaciones']]);

        expect(loggedEntry()->summary)->toBe('Cliente en vacaciones')->and(loggedEntry()->details)->toBeNull();
    });

    it('shortens a long first line in the summary and keeps the whole text', function () {
        $text = str_repeat('palabra ', 30).'fin';

        $this->actingAs(CarteraUsers::analyst())->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => $text]]);

        expect(mb_strlen(loggedEntry()->summary))->toBeLessThanOrEqual(100)
            ->and(loggedEntry()->summary)->toEndWith('…')
            ->and(loggedEntry()->details)->toBe($text);
    });

    it('needs some text, and not too much', function (mixed $text) {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => $text]])
            ->assertSessionHasErrors('nota.detalle');

        $this->fakes->gestiones->assertNothingLogged();
    })->with(['missing' => [null], 'blank' => ['   '], 'too long' => [str_repeat('a', 2001)]]);
});

describe('a discount offered', function () {
    it('is logged with its value', function () {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, ['tipo' => 'descuento', 'descuento' => ['valor' => '350000', 'detalle' => 'Por pago de contado']])
            ->assertSessionHas('status', 'Descuento registrado.');

        $entry = loggedEntry();

        expect($entry->type)->toBe(ActivityType::DiscountOffered)
            ->and($entry->summary)->toBe('Descuento ofrecido: '.Money::format(350_000))
            ->and($entry->details)->toBe('Por pago de contado')
            ->and($entry->metadata)->toBe(['discount_amount' => 350000.0]);
    });

    it('needs a value above zero', function (mixed $value) {
        $this->actingAs(CarteraUsers::analyst())
            ->post($this->url, ['tipo' => 'descuento', 'descuento' => ['valor' => $value]])
            ->assertSessionHasErrors('descuento.valor');

        $this->fakes->gestiones->assertNothingLogged();
    })->with(['missing' => [null], 'zero' => ['0'], 'negative' => ['-5'], 'text' => ['mucho'], 'absurd' => ['99999999999999']]);
});

it('refuses a kind of entry it does not log', function (mixed $kind) {
    $this->actingAs(CarteraUsers::analyst())
        ->post($this->url, ['tipo' => $kind, 'nota' => ['detalle' => 'Hola']])
        ->assertSessionHasErrors('tipo');

    $this->fakes->gestiones->assertNothingLogged();
})->with(['missing' => [null], 'a system entry' => ['sistema'], 'an e-mail' => ['correo_enviado']]);

it('goes back to the client file when something fails, whatever page sent the form', function () {
    $this->actingAs(CarteraUsers::analyst())
        ->from('/cartera/kanban')
        ->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => '']])
        ->assertRedirect($this->file);
});

it('answers 404 for a client that does not exist or is out of reach', function () {
    $this->actingAs(CarteraUsers::analyst())->post('/cartera/clientes/987654/gestiones', ['tipo' => 'nota', 'nota' => ['detalle' => 'Hola']])->assertNotFound();

    $this->fakes->cartera->restrictToAdvisor(999);

    $this->actingAs(CarteraUsers::analyst())->post($this->url, ['tipo' => 'nota', 'nota' => ['detalle' => 'Hola']])->assertNotFound();
});

describe('the buttons in the client file', function () {
    it('open a form for each kind of entry', function () {
        $html = $this->actingAs(CarteraUsers::analyst())->get($this->file)->assertOk()->getContent();

        foreach (['llamada', 'nota', 'descuento'] as $kind) {
            expect($html)->toContain('data-modal-open="gestion-'.$kind.'"')
                ->and($html)->toContain('id="gestion-'.$kind.'"');
        }

        expect($html)->toContain('action="'.route('cartera.gestiones.store', $this->client->id).'"')
            ->and($html)->toContain('Contactado — promete pago');
    });

    it('are not there for those who may only look', function () {
        $html = $this->actingAs(CarteraUsers::viewer())->get($this->file)->assertOk()->getContent();

        expect($html)->not->toContain('data-modal-open="gestion-llamada"')
            ->and($html)->not->toContain(route('cartera.gestiones.store', $this->client->id));
    });

    it('reopen only the form that failed, with what was typed', function () {
        $response = $this->actingAs(CarteraUsers::analyst())
            ->followingRedirects()
            ->post($this->url, ['tipo' => 'descuento', 'descuento' => ['valor' => '0', 'detalle' => 'Por pago de contado']]);

        $response->assertOk();

        expect($response->getContent())->toMatch('/<dialog[^>]*id="gestion-descuento"[^>]*data-modal-autoopen/s')
            ->and($response->getContent())->not->toMatch('/<dialog[^>]*id="gestion-llamada"[^>]*data-modal-autoopen/s')
            ->and($response->getContent())->not->toMatch('/<dialog[^>]*id="gestion-nota"[^>]*data-modal-autoopen/s')
            ->and($response->getContent())->toContain('Por pago de contado');
    });
});
