<?php

use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

/**
 * Makes old() return $input, as after a failed form submission.
 *
 * @param  array<string, mixed>  $input
 */
function moneyInputWithOldInput(array $input): void
{
    $session = app('session.store');
    $session->put('_old_input', $input);
    request()->setLaravelSession($session);
}

describe('<x-ui.money-input>', function () {
    it('renders a text input for whole pesos, wired to the money-input script', function () {
        $html = Html::parse((string) $this->blade('<x-ui.money-input name="compromiso[valor]" label="Valor del compromiso" :required="true" />'));

        $input = $html->labelledControl('Valor del compromiso');

        expect($input->getAttribute('type'))->toBe('text')
            ->and($input->getAttribute('name'))->toBe('compromiso[valor]')
            ->and($input->getAttribute('id'))->toBe('compromiso-valor')
            ->and($input->getAttribute('inputmode'))->toBe('numeric')
            ->and($input->getAttribute('autocomplete'))->toBe('off')
            ->and($input->hasAttribute('data-money-input'))->toBeTrue()
            ->and($input->getAttribute('data-decimals'))->toBe('0')
            ->and($input->hasAttribute('required'))->toBeTrue()
            ->and($html->text('//*[@data-part="prefix"]'))->toBe('$');
    });

    it('asks for the decimal keypad when the field takes cents', function () {
        $input = Html::parse((string) $this->blade('<x-ui.money-input name="valor" label="Valor pagado" :decimals="2" />'))->labelledControl('Valor pagado');

        expect($input->getAttribute('inputmode'))->toBe('decimal')
            ->and($input->getAttribute('data-decimals'))->toBe('2');
    });

    it('prints the clean value for the script to format, and old input wins after a failed submit', function () {
        $input = Html::parse((string) $this->blade('<x-ui.money-input name="valor" label="Valor" value="1500000" />'))->labelledControl('Valor');

        expect($input->getAttribute('value'))->toBe('1500000');

        moneyInputWithOldInput(['valor' => '2750000.5']);

        $input = Html::parse((string) $this->blade('<x-ui.money-input name="valor" label="Valor" value="1500000" :decimals="2" />'))->labelledControl('Valor');

        expect($input->getAttribute('value'))->toBe('2750000.5');
    });

    it('keeps the hint, the validation error and extra attributes of <x-ui.input>', function () {
        $this->withViewErrors(['abono.valor' => 'Escribe el valor del abono.']);

        $html = Html::parse((string) $this->blade(
            '<x-ui.money-input name="abono[valor]" label="Abono" hint="En pesos." form="abono-form" placeholder="0" />',
        ));

        $input = $html->labelledControl('Abono');

        expect($input->getAttribute('aria-invalid'))->toBe('true')
            ->and($html->descriptionsOf($input))->toBe(['En pesos.', 'Escribe el valor del abono.'])
            ->and($input->getAttribute('form'))->toBe('abono-form')
            ->and($input->getAttribute('placeholder'))->toBe('0');
    });

    it('refuses more than two decimals', function () {
        $this->blade('<x-ui.money-input name="valor" label="Valor" :decimals="3" />');
    })->throws(ViewException::class, 'decimals');
});

it('shows <x-ui.money-input> in the component gallery', function () {
    $this->app['env'] = 'local';

    $html = Html::parse($this->get(route('dev.componentes'))->assertOk()->getContent() ?: '');

    $section = $html->byId('component-ui-money-input');

    expect($section->nodeName)->toBe('section')
        ->and(Html::clean($html->byId($section->getAttribute('aria-labelledby'))->textContent))->toBe('<x-ui.money-input>')
        ->and($html->count('//*[@id="component-ui-money-input"]//input[@data-money-input]'))->toBeGreaterThan(0);
});
