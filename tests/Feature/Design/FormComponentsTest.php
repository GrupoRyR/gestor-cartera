<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

/**
 * Makes old() return $input, as after a failed form submission.
 *
 * @param  array<string, mixed>  $input
 */
function withOldInput(array $input): void
{
    $session = app('session.store');
    $session->put('_old_input', $input);
    request()->setLaravelSession($session);
}

describe('<x-ui.input>', function () {
    it('labels the input and derives its id from the name', function () {
        $html = Html::parse((string) $this->blade('<x-ui.input name="clientes[0][email]" label="Correo del cliente" type="email" />'));

        $input = $html->labelledControl('Correo del cliente');

        expect($input->nodeName)->toBe('input')
            ->and($input->getAttribute('id'))->toBe('clientes-0-email')
            ->and($input->getAttribute('name'))->toBe('clientes[0][email]')
            ->and($input->getAttribute('type'))->toBe('email')
            ->and($input->hasAttribute('aria-invalid'))->toBeFalse()
            ->and($input->hasAttribute('required'))->toBeFalse();
    });

    it('uses text inputs by default and keeps a given id', function () {
        $input = Html::parse((string) $this->blade('<x-ui.input name="nit" label="NIT" id="cliente-nit" />'))->labelledControl('NIT');

        expect($input->getAttribute('type'))->toBe('text')
            ->and($input->getAttribute('id'))->toBe('cliente-nit');
    });

    it('describes the input with its hint and marks validation errors', function () {
        $this->withViewErrors(['clientes.0.email' => 'Escribe un correo válido.']);

        $html = Html::parse((string) $this->blade(
            '<x-ui.input name="clientes[0][email]" label="Correo" hint="Le llegan los estados de cuenta." />',
        ));

        $input = $html->labelledControl('Correo');

        expect($input->getAttribute('aria-invalid'))->toBe('true')
            ->and($html->descriptionsOf($input))->toBe(['Le llegan los estados de cuenta.', 'Escribe un correo válido.']);
    });

    it('keeps an aria-describedby given by the caller', function () {
        $html = Html::parse((string) $this->blade(
            '<p id="nota">Nota general</p><x-ui.input name="valor" label="Valor" hint="Sin decimales." aria-describedby="nota" />',
        ));

        expect($html->descriptionsOf($html->labelledControl('Valor')))->toBe(['Nota general', 'Sin decimales.']);
    });

    it('shows the value, and old input after a failed submit', function () {
        $fresh = Html::parse((string) $this->blade('<x-ui.input name="nombre" label="Nombre" value="Ferretería El Tornillo" />'));

        withOldInput(['nombre' => 'Ferretería La Tuerca', 'clientes' => [['email' => 'pagos@tuerca.co']]]);

        $submitted = Html::parse((string) $this->blade('<x-ui.input name="nombre" label="Nombre" value="Ferretería El Tornillo" />'));
        $nested = Html::parse((string) $this->blade('<x-ui.input name="clientes[0][email]" label="Correo" />'));

        expect($fresh->labelledControl('Nombre')->getAttribute('value'))->toBe('Ferretería El Tornillo')
            ->and($submitted->labelledControl('Nombre')->getAttribute('value'))->toBe('Ferretería La Tuerca')
            ->and($nested->labelledControl('Correo')->getAttribute('value'))->toBe('pagos@tuerca.co');
    });

    it('never repopulates passwords', function () {
        withOldInput(['clave' => 'secreta']);

        $input = Html::parse((string) $this->blade('<x-ui.input name="clave" label="Clave" type="password" value="otra" />'))->labelledControl('Clave');

        expect($input->hasAttribute('value'))->toBeFalse();
    });

    it('supports required fields, hidden labels, affixes and extra attributes', function () {
        $html = Html::parse((string) $this->blade(
            '<x-ui.input name="valor" label="Valor del compromiso" prefix="$" suffix="COP" :required="true" :label-hidden="true" inputmode="numeric" autocomplete="off" />',
        ));

        $input = $html->labelledControl('Valor del compromiso');

        expect($input->hasAttribute('required'))->toBeTrue()
            ->and($input->getAttribute('inputmode'))->toBe('numeric')
            ->and($input->getAttribute('autocomplete'))->toBe('off')
            ->and($html->text('//body'))->toBe('Valor del compromiso $ COP');
    });

    it('needs a name and a label', function (string $template) {
        expect(fn () => $this->blade($template))->toThrow(ViewException::class);
    })->with([
        'name' => '<x-ui.input label="Correo" />',
        'label' => '<x-ui.input name="correo" />',
    ]);
});

describe('<x-ui.select>', function () {
    it('labels the select and lists the options', function () {
        $html = Html::parse((string) $this->blade(
            '<x-ui.select name="estado" label="Estado" :options="$options" placeholder="Todos" />',
            ['options' => ClientStatus::options()],
        ));

        $select = $html->labelledControl('Estado');
        $options = $html->all('//select/option');

        expect($select->nodeName)->toBe('select')
            ->and($options)->toHaveCount(count(ClientStatus::cases()) + 1)
            ->and($options[0]->getAttribute('value'))->toBe('')
            ->and(Html::clean($options[0]->textContent))->toBe('Todos')
            ->and(Html::clean($options[3]->textContent))->toBe('Vencida')
            ->and($html->count('//option[@selected]'))->toBe(0);
    });

    it('selects the option of an enum value', function () {
        $html = Html::parse((string) $this->blade(
            '<x-ui.select name="estado" label="Estado" :options="$options" :value="$value" />',
            ['options' => ClientStatus::options(), 'value' => ClientStatus::Overdue],
        ));

        expect($html->one('//option[@selected]')->getAttribute('value'))->toBe('vencida');
    });

    it('prefers old input after a failed submit', function () {
        withOldInput(['filtros' => ['estado' => 'juridico']]);

        $html = Html::parse((string) $this->blade(
            '<x-ui.select name="filtros[estado]" label="Estado" :options="$options" value="vencida" />',
            ['options' => ClientStatus::options()],
        ));

        expect($html->one('//option[@selected]')->getAttribute('value'))->toBe('juridico');
    });

    it('marks validation errors and describes the select', function () {
        $this->withViewErrors(['estado' => 'Elige un estado.']);

        $html = Html::parse((string) $this->blade(
            '<x-ui.select name="estado" label="Estado" :options="$options" hint="Solo estados manuales." :required="true" />',
            ['options' => ClientStatus::options()],
        ));

        $select = $html->labelledControl('Estado');

        expect($select->getAttribute('aria-invalid'))->toBe('true')
            ->and($select->hasAttribute('required'))->toBeTrue()
            ->and($html->descriptionsOf($select))->toBe(['Solo estados manuales.', 'Elige un estado.']);
    });
});

describe('<x-ui.checkbox>', function () {
    it('labels each checkbox of a list with its own id', function () {
        $html = Html::parse((string) $this->blade(
            '<x-ui.checkbox name="clientes[]" value="C-001" label="Ferretería El Tornillo" />'
            .'<x-ui.checkbox name="clientes[]" value="C-002" label="Distribuciones Andina" />',
        ));

        $first = $html->labelledControl('Ferretería El Tornillo');
        $second = $html->labelledControl('Distribuciones Andina');

        expect($first->getAttribute('type'))->toBe('checkbox')
            ->and($first->getAttribute('value'))->toBe('C-001')
            ->and($second->getAttribute('value'))->toBe('C-002')
            ->and($first->getAttribute('id'))->not->toBe($second->getAttribute('id'));
    });

    it('sends 1 by default and is unchecked without old input', function () {
        $input = Html::parse((string) $this->blade('<x-ui.checkbox name="activo" label="Activo" />'))->labelledControl('Activo');

        expect($input->getAttribute('value'))->toBe('1')
            ->and($input->hasAttribute('checked'))->toBeFalse();
    });

    it('follows an explicit checked state', function () {
        $input = Html::parse((string) $this->blade('<x-ui.checkbox name="activo" label="Activo" :checked="true" />'))->labelledControl('Activo');

        expect($input->hasAttribute('checked'))->toBeTrue();
    });

    it('restores the checked state from old input, including lists', function () {
        withOldInput(['activo' => '1', 'clientes' => ['C-002']]);

        $html = Html::parse((string) $this->blade(
            '<x-ui.checkbox name="activo" label="Activo" />'
            .'<x-ui.checkbox name="clientes[]" value="C-001" label="Ferretería El Tornillo" />'
            .'<x-ui.checkbox name="clientes[]" value="C-002" label="Distribuciones Andina" />',
        ));

        expect($html->labelledControl('Activo')->hasAttribute('checked'))->toBeTrue()
            ->and($html->labelledControl('Ferretería El Tornillo')->hasAttribute('checked'))->toBeFalse()
            ->and($html->labelledControl('Distribuciones Andina')->hasAttribute('checked'))->toBeTrue();
    });

    it('marks validation errors of the list and describes the checkbox', function () {
        $this->withViewErrors(['clientes' => 'Elige al menos un cliente.']);

        $html = Html::parse((string) $this->blade('<x-ui.checkbox name="clientes[]" value="C-001" label="Ferretería El Tornillo" hint="Recibe el estado de cuenta." />'));

        $input = $html->labelledControl('Ferretería El Tornillo');

        expect($input->getAttribute('aria-invalid'))->toBe('true')
            ->and($html->descriptionsOf($input))->toBe(['Recibe el estado de cuenta.', 'Elige al menos un cliente.']);
    });
});

describe('<x-ui.textarea>', function () {
    it('labels the textarea and shows its value', function () {
        $html = Html::parse((string) $this->blade('<x-ui.textarea name="observacion" label="Observación" value="Llamar el lunes." />'));

        $textarea = $html->labelledControl('Observación');

        expect($textarea->nodeName)->toBe('textarea')
            ->and($textarea->getAttribute('rows'))->toBe('4')
            ->and($textarea->textContent)->toBe('Llamar el lunes.');
    });

    it('prefers old input and takes the number of rows', function () {
        withOldInput(['observacion' => 'Pagará el viernes.']);

        $textarea = Html::parse((string) $this->blade('<x-ui.textarea name="observacion" label="Observación" value="Llamar el lunes." :rows="8" />'))
            ->labelledControl('Observación');

        expect($textarea->getAttribute('rows'))->toBe('8')
            ->and($textarea->textContent)->toBe('Pagará el viernes.');
    });

    it('marks validation errors and describes the textarea', function () {
        $this->withViewErrors(['observacion' => 'Escribe una observación.']);

        $html = Html::parse((string) $this->blade('<x-ui.textarea name="observacion" label="Observación" hint="Queda en la bitácora." :required="true" />'));

        $textarea = $html->labelledControl('Observación');

        expect($textarea->getAttribute('aria-invalid'))->toBe('true')
            ->and($textarea->hasAttribute('required'))->toBeTrue()
            ->and($html->descriptionsOf($textarea))->toBe(['Queda en la bitácora.', 'Escribe una observación.']);
    });
});
