<?php

use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

describe('<x-ui.page-header>', function () {
    it('renders the page heading with its context, description and actions', function () {
        $html = Html::parse((string) $this->blade(<<<'BLADE'
            <x-ui.page-header title="Clientes" context="Cartera · Segurex" description="Clientes con saldo pendiente.">
                <x-slot:actions>
                    <x-ui.link-button href="/cartera/exportar">Exportar</x-ui.link-button>
                </x-slot:actions>
            </x-ui.page-header>
            BLADE));

        expect($html->text('//h1'))->toBe('Clientes')
            ->and($html->text('//header'))->toContain('Cartera · Segurex')
            ->and($html->text('//header'))->toContain('Clientes con saldo pendiente.')
            ->and($html->text('//header//a'))->toBe('Exportar');
    });

    it('renders only the heading when nothing else is given', function () {
        $html = Html::parse((string) $this->blade('<x-ui.page-header title="Tablero" />'));

        expect($html->text('//header'))->toBe('Tablero');
    });

    it('needs a title', function () {
        expect(fn () => $this->blade('<x-ui.page-header />'))->toThrow(ViewException::class, '$title');
    });
});

describe('<x-ui.card>', function () {
    it('is a section named by its heading', function () {
        $html = Html::parse((string) $this->blade(<<<'BLADE'
            <x-ui.card title="Recaudo de la semana" description="Lunes a domingo.">
                <p>Contenido</p>
                <x-slot:actions><x-ui.button>Actualizar</x-ui.button></x-slot:actions>
                <x-slot:footer>Fuente: bancos</x-slot:footer>
            </x-ui.card>
            BLADE));

        $section = $html->one('//section');
        $heading = $html->byId($section->getAttribute('aria-labelledby'));

        expect($heading->nodeName)->toBe('h2')
            ->and(Html::clean($heading->textContent))->toBe('Recaudo de la semana')
            ->and(Html::clean($section->textContent))->toContain('Lunes a domingo.')
            ->and(Html::clean($section->textContent))->toContain('Contenido')
            ->and(Html::clean($section->textContent))->toContain('Fuente: bancos')
            ->and($html->text('//section//button'))->toBe('Actualizar');
    });

    it('uses the requested heading level', function () {
        $html = Html::parse((string) $this->blade('<x-ui.card title="Detalle" :level="3">Contenido</x-ui.card>'));

        expect($html->text('//h3'))->toBe('Detalle')
            ->and($html->count('//h2'))->toBe(0);
    });

    it('is not a named section without a title', function () {
        $html = Html::parse((string) $this->blade('<x-ui.card data-testid="resumen">Contenido</x-ui.card>'));

        expect($html->count('//section'))->toBe(0)
            ->and($html->count('//*[@aria-labelledby]'))->toBe(0)
            ->and($html->text('//*[@data-testid="resumen"]'))->toBe('Contenido');
    });

    it('gives every titled card its own heading id', function () {
        $html = Html::parse((string) $this->blade('<x-ui.card title="Uno">A</x-ui.card><x-ui.card title="Dos">B</x-ui.card>'));

        $sections = $html->all('//section');

        expect($sections[0]->getAttribute('aria-labelledby'))->not->toBe($sections[1]->getAttribute('aria-labelledby'));
    });

    it('rejects heading levels outside 2 to 4', function () {
        expect(fn () => $this->blade('<x-ui.card title="Detalle" :level="5">Contenido</x-ui.card>'))
            ->toThrow(ViewException::class, 'level');
    });
});

describe('<x-ui.stat>', function () {
    it('shows the label and the formatted value', function () {
        $html = Html::parse((string) $this->blade('<x-ui.stat label="Clientes vencidos" value="128" hint="12 más que ayer" />'));

        $tile = $html->one('//*[@data-tone]');

        expect($tile->getAttribute('data-tone'))->toBe('neutral')
            ->and(Html::clean($tile->textContent))->toBe('Clientes vencidos 128 12 más que ayer');
    });

    it('lets the slot replace the value', function () {
        $html = Html::parse((string) $this->blade('<x-ui.stat label="Saldo vencido" value="no se usa"><x-ui.money :value="1500000" /></x-ui.stat>'));

        expect($html->text('//*[@data-tone]'))->toBe("Saldo vencido \$\u{00A0}1.500.000");
    });

    it('describes the trend in words', function (string $trend, string $words) {
        $html = Html::parse((string) $this->blade('<x-ui.stat label="Recaudo" value="10" :trend="$trend" />', ['trend' => $trend]));

        expect($html->text('//*[@data-tone]'))->toBe("Recaudo 10 {$words}");
    })->with([
        ['up', 'Sube'],
        ['down', 'Baja'],
        ['flat', 'Sin cambios'],
    ]);

    it('accepts a custom trend label and tone', function () {
        $html = Html::parse((string) $this->blade('<x-ui.stat label="Mora" value="8 %" trend="down" trend-label="Baja 2 puntos" tone="success" />'));

        $tile = $html->one('//*[@data-tone]');

        expect($tile->getAttribute('data-tone'))->toBe('success')
            ->and(Html::clean($tile->textContent))->toBe('Mora 8 % Baja 2 puntos');
    });

    it('turns the whole tile into a link', function () {
        $html = Html::parse((string) $this->blade('<x-ui.stat label="Compromisos de hoy" value="5" href="/cartera/compromisos" />'));

        expect($html->one('//a')->getAttribute('href'))->toBe('/cartera/compromisos')
            ->and($html->text('//a'))->toBe('Compromisos de hoy 5');
    });

    it('rejects unknown tones and trends', function (string $template) {
        expect(fn () => $this->blade($template))->toThrow(ViewException::class);
    })->with([
        'tone' => '<x-ui.stat label="Mora" value="8" tone="rojo" />',
        'trend' => '<x-ui.stat label="Mora" value="8" trend="arriba" />',
    ]);
});

describe('<x-ui.chip>', function () {
    it('shows its text with a neutral tone and a decorative dot', function () {
        $html = Html::parse((string) $this->blade('<x-ui.chip>Pendiente</x-ui.chip>'));

        $chip = $html->one('//*[@data-tone]');

        expect($chip->getAttribute('data-tone'))->toBe('neutral')
            ->and(Html::clean($chip->textContent))->toBe('Pendiente')
            ->and($html->count('//*[@data-tone]//*[@aria-hidden="true"]'))->toBe(1);
    });

    it('takes a tone and can drop the dot', function () {
        $html = Html::parse((string) $this->blade('<x-ui.chip tone="success" :dot="false">Pagado</x-ui.chip>'));

        expect($html->one('//*[@data-tone]')->getAttribute('data-tone'))->toBe('success')
            ->and($html->count('//*[@aria-hidden="true"]'))->toBe(0);
    });

    it('never relies on color alone', function () {
        expect(fn () => $this->blade('<x-ui.chip tone="danger"></x-ui.chip>'))->toThrow(ViewException::class, 'text');
    });

    it('rejects unknown tones', function () {
        expect(fn () => $this->blade('<x-ui.chip tone="naranja">Hoy</x-ui.chip>'))->toThrow(ViewException::class);
    });
});

describe('<x-ui.empty>', function () {
    it('explains what is missing and offers the next step', function () {
        $html = Html::parse((string) $this->blade(<<<'BLADE'
            <x-ui.empty title="No hay compromisos para hoy" description="Los compromisos nuevos aparecen aquí." icon="calendar">
                <x-ui.link-button href="/cartera/compromisos/nuevo">Registrar compromiso</x-ui.link-button>
            </x-ui.empty>
            BLADE));

        expect($html->text('//body'))->toBe('No hay compromisos para hoy Los compromisos nuevos aparecen aquí. Registrar compromiso')
            ->and($html->count('//*[@data-icon="calendar"]'))->toBe(1)
            ->and($html->one('//a')->getAttribute('href'))->toBe('/cartera/compromisos/nuevo');
    });

    it('needs a title', function () {
        expect(fn () => $this->blade('<x-ui.empty />'))->toThrow(ViewException::class, '$title');
    });
});

describe('<x-ui.alert>', function () {
    it('uses role status for information and success', function (string $tone) {
        $alert = Html::parse((string) $this->blade('<x-ui.alert :tone="$tone">Listo</x-ui.alert>', ['tone' => $tone]))->one('//*[@role]');

        expect($alert->getAttribute('role'))->toBe('status')
            ->and($alert->getAttribute('data-tone'))->toBe($tone);
    })->with(['info', 'success']);

    it('uses role alert for warnings and errors', function (string $tone) {
        $alert = Html::parse((string) $this->blade('<x-ui.alert :tone="$tone">Revisa</x-ui.alert>', ['tone' => $tone]))->one('//*[@role]');

        expect($alert->getAttribute('role'))->toBe('alert')
            ->and($alert->getAttribute('data-tone'))->toBe($tone);
    })->with(['warning', 'danger']);

    it('shows a title and the message', function () {
        $html = Html::parse((string) $this->blade('<x-ui.alert title="Carga incompleta">Faltan 3 archivos.</x-ui.alert>'));

        expect($html->text('//*[@role="status"]'))->toBe('Carga incompleta Faltan 3 archivos.')
            ->and($html->count('//button'))->toBe(0);
    });

    it('can be dismissed with a labelled button', function () {
        $html = Html::parse((string) $this->blade('<x-ui.alert tone="warning" :dismissible="true">Revisa los datos.</x-ui.alert>'));

        $alert = $html->one('//*[@role="alert"]');
        $button = $html->one('//*[@role="alert"]//button');

        expect($alert->hasAttribute('x-data'))->toBeTrue()
            ->and(Html::clean($button->textContent))->toBe('Cerrar aviso')
            ->and($button->getAttribute('type'))->toBe('button');
    });

    it('rejects unknown tones', function () {
        expect(fn () => $this->blade('<x-ui.alert tone="error">Falló</x-ui.alert>'))->toThrow(ViewException::class);
    });
});
