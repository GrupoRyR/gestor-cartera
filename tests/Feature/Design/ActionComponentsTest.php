<?php

use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

describe('<x-ui.button>', function () {
    it('renders a primary button that does not submit by default', function () {
        $button = Html::parse((string) $this->blade('<x-ui.button>Guardar</x-ui.button>'))->one('//button');

        expect($button->getAttribute('type'))->toBe('button')
            ->and($button->getAttribute('data-variant'))->toBe('primary')
            ->and(Html::clean($button->textContent))->toBe('Guardar');
    });

    it('takes the variant and type and passes other attributes through', function () {
        $button = Html::parse((string) $this->blade(
            '<x-ui.button variant="danger" type="submit" form="borrar" name="accion" value="borrar" data-modal-open="confirmar" disabled>Borrar</x-ui.button>',
        ))->one('//button');

        expect($button->getAttribute('type'))->toBe('submit')
            ->and($button->getAttribute('data-variant'))->toBe('danger')
            ->and($button->getAttribute('form'))->toBe('borrar')
            ->and($button->getAttribute('name'))->toBe('accion')
            ->and($button->getAttribute('value'))->toBe('borrar')
            ->and($button->getAttribute('data-modal-open'))->toBe('confirmar')
            ->and($button->hasAttribute('disabled'))->toBeTrue();
    });

    it('keeps the text of an icon-only button for screen readers', function () {
        $html = Html::parse((string) $this->blade('<x-ui.button icon="search" :icon-only="true">Buscar</x-ui.button>'));

        expect($html->text('//button'))->toBe('Buscar')
            ->and($html->one('//button//*[@data-icon="search"]')->getAttribute('aria-hidden'))->toBe('true');
    });

    it('rejects unknown variants and types', function (string $template) {
        expect(fn () => $this->blade($template))->toThrow(ViewException::class);
    })->with([
        'variant' => '<x-ui.button variant="primario">Guardar</x-ui.button>',
        'type' => '<x-ui.button type="link">Guardar</x-ui.button>',
    ]);

    it('needs text, and an icon when only the icon is visible', function (string $template) {
        expect(fn () => $this->blade($template))->toThrow(ViewException::class);
    })->with([
        'no text' => '<x-ui.button icon="search" :icon-only="true"></x-ui.button>',
        'no icon' => '<x-ui.button :icon-only="true">Buscar</x-ui.button>',
    ]);
});

describe('<x-ui.link-button>', function () {
    it('renders a link with the button variants', function () {
        $link = Html::parse((string) $this->blade(
            '<x-ui.link-button href="/cartera/clientes" variant="secondary" data-testid="volver">Ver clientes</x-ui.link-button>',
        ))->one('//a');

        expect($link->getAttribute('href'))->toBe('/cartera/clientes')
            ->and($link->getAttribute('data-variant'))->toBe('secondary')
            ->and($link->getAttribute('data-testid'))->toBe('volver')
            ->and($link->hasAttribute('target'))->toBeFalse()
            ->and(Html::clean($link->textContent))->toBe('Ver clientes');
    });

    it('opens external links in a new tab and says so', function () {
        $link = Html::parse((string) $this->blade(
            '<x-ui.link-button href="https://www.dian.gov.co" :external="true">Consultar en la DIAN</x-ui.link-button>',
        ))->one('//a');

        expect($link->getAttribute('target'))->toBe('_blank')
            ->and($link->getAttribute('rel'))->toBe('noopener noreferrer')
            ->and(Html::clean($link->textContent))->toBe('Consultar en la DIAN (se abre en otra pestaña)');
    });

    it('needs an href', function () {
        expect(fn () => $this->blade('<x-ui.link-button>Volver</x-ui.link-button>'))
            ->toThrow(ViewException::class, '$href');
    });
});

describe('<x-ui.modal>', function () {
    it('renders a dialog named by its title with a close button', function () {
        $html = Html::parse((string) $this->blade(<<<'BLADE'
            <x-ui.modal id="confirmar-borrado" title="¿Borrar el compromiso?">
                <p>Esta acción no se puede deshacer.</p>
                <x-slot:footer>
                    <x-ui.button variant="danger" type="submit">Borrar</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
            BLADE));

        $dialog = $html->one('//dialog[@id="confirmar-borrado"]');
        $heading = $html->byId($dialog->getAttribute('aria-labelledby'));
        $close = $html->one('//dialog//button[@data-modal-close]');

        expect(Html::clean($heading->textContent))->toBe('¿Borrar el compromiso?')
            ->and($heading->nodeName)->toBe('h2')
            ->and(Html::clean($close->textContent))->toBe('Cerrar')
            ->and($close->getAttribute('type'))->toBe('button')
            ->and($dialog->hasAttribute('data-modal-autoopen'))->toBeFalse()
            ->and(Html::clean($dialog->textContent))->toContain('Esta acción no se puede deshacer.')
            ->and($html->text('//dialog//button[@type="submit"]'))->toBe('Borrar');
    });

    it('opens on page load when asked', function () {
        $html = Html::parse((string) $this->blade('<x-ui.modal id="editar" title="Editar" :open="true">Formulario</x-ui.modal>'));

        expect($html->one('//dialog[@id="editar"]')->hasAttribute('data-modal-autoopen'))->toBeTrue();
    });

    it('needs an id and a title', function (string $template) {
        expect(fn () => $this->blade($template))->toThrow(ViewException::class);
    })->with([
        'id' => '<x-ui.modal title="Editar">Formulario</x-ui.modal>',
        'title' => '<x-ui.modal id="editar">Formulario</x-ui.modal>',
    ]);
});

describe('<x-ui.tabs>', function () {
    it('renders navigation links and marks the current tab', function () {
        $html = Html::parse((string) $this->blade('<x-ui.tabs label="Vistas de la cartera" :items="$items" current="vencidas" />', [
            'items' => [
                ['key' => 'todas', 'label' => 'Todas', 'href' => '/cartera?vista=todas'],
                ['key' => 'vencidas', 'label' => 'Vencidas', 'href' => '/cartera?vista=vencidas', 'count' => 12],
            ],
        ]));

        $links = $html->all('//nav[@aria-label="Vistas de la cartera"]//a');

        expect($links)->toHaveCount(2)
            ->and($links[0]->getAttribute('href'))->toBe('/cartera?vista=todas')
            ->and($links[0]->hasAttribute('aria-current'))->toBeFalse()
            ->and($links[1]->getAttribute('aria-current'))->toBe('page')
            ->and(Html::clean($links[1]->textContent))->toBe('Vencidas 12');
    });

    it('marks no tab when there is no current one', function () {
        $html = Html::parse((string) $this->blade('<x-ui.tabs label="Vistas" :items="$items" />', [
            'items' => [['key' => 'todas', 'label' => 'Todas', 'href' => '/cartera']],
        ]));

        expect($html->count('//*[@aria-current]'))->toBe(0);
    });
});
