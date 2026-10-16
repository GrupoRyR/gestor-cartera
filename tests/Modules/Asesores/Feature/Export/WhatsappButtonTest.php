<?php

use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Tests\Modules\Asesores\Support\AdvisorWorld;
use Tests\Modules\Asesores\Support\Page;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(AdvisorWorld::TODAY, 'America/Bogota'));
    $this->world = AdvisorWorld::create();
    $this->agro = $this->world->clients['agro'];
});

/**
 * The WhatsApp link of the client file as the advisor sees it.
 */
function asesoresWhatsappLink($response): ?DOMElement
{
    $links = Page::html($response)->all('//a[starts-with(@href,"https://wa.me/")]');

    return $links[0] ?? null;
}

it('opens the chat of the client mobile with the account statement ready', function () {
    ContactRecord::factory()->primary()->create(['client_id' => $this->agro->id, 'phone' => '+57 311 555 0101']);

    $link = asesoresWhatsappLink($this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id)));
    $href = $link->getAttribute('href');

    expect($href)->toStartWith('https://wa.me/573115550101?text=');

    $message = rawurldecode(explode('?text=', $href)[1]);

    expect($message)->toContain('Buen día, Agro Arango.')
        ->toContain('Segurex Latam')
        ->toContain('Saldo total: '.str_replace("\u{00A0}", ' ', Money::format(7_000_000)))
        ->toContain('Vencido: '.str_replace("\u{00A0}", ' ', Money::format(5_000_000)))
        ->toContain('(100 días de mora)');
});

it('opens in another tab, so the file stays where it is', function () {
    ContactRecord::factory()->primary()->create(['client_id' => $this->agro->id, 'phone' => '3115550101']);

    $link = asesoresWhatsappLink($this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id)));

    expect($link->getAttribute('target'))->toBe('_blank')->and($link->getAttribute('rel'))->toContain('noopener');
});

it('lets the advisor choose the contact when there is no mobile', function () {
    ContactRecord::factory()->primary()->create(['client_id' => $this->agro->id, 'phone' => '601 555 0123']);

    $response = $this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id));

    expect(asesoresWhatsappLink($response)->getAttribute('href'))->toStartWith('https://wa.me/?text=');
    $response->assertSeeText('No tenemos un celular de este cliente');
});

it('uses the first contact that has a mobile', function () {
    ContactRecord::factory()->primary()->create(['client_id' => $this->agro->id, 'phone' => '601 555 0123']);
    ContactRecord::factory()->create(['client_id' => $this->agro->id, 'phone' => '320 555 0202']);

    $link = asesoresWhatsappLink($this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id)));

    expect($link->getAttribute('href'))->toStartWith('https://wa.me/573205550202?text=');
});

it('says only what this client owes', function () {
    $href = asesoresWhatsappLink($this->actingAs($this->world->ana)->get(route('asesores.clientes.show', $this->agro->id)))->getAttribute('href');
    $message = rawurldecode(explode('?text=', $href)[1]);

    expect($message)->not->toContain('Bodega')->not->toContain('Ferretería Franco');
});

it('is also on the files of a sales manager, for the clients of the team', function () {
    $response = $this->actingAs($this->world->gloria)->get(route('asesores.clientes.show', $this->world->clients['comercial']->id));

    expect(asesoresWhatsappLink($response))->not->toBeNull();
});
