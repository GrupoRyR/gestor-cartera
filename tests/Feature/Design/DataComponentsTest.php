<?php

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

/**
 * Columns of a client list: two sortable, one not.
 *
 * @return list<array<string, mixed>>
 */
function clientColumns(): array
{
    return [
        ['key' => 'name', 'label' => 'Cliente', 'sortable' => true],
        ['key' => 'overdue', 'label' => 'Saldo vencido', 'sortable' => true, 'align' => 'end'],
        ['key' => 'status', 'label' => 'Estado'],
    ];
}

/**
 * Sends the next renders to $uri, so components read its query string.
 */
function onPage(string $uri): void
{
    app()->instance('request', Request::create($uri));
}

/**
 * The query string of $url as an array.
 *
 * @return array<string, mixed>
 */
function queryOf(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

describe('<x-ui.table>', function () {
    it('wraps the table in a focusable region named by the caption', function () {
        $html = Html::parse((string) $this->blade('<x-ui.table caption="Clientes con saldo" :columns="$columns" />', ['columns' => clientColumns()]));

        $region = $html->one('//*[@role="region"]');

        expect($region->getAttribute('aria-label'))->toBe('Clientes con saldo')
            ->and($region->getAttribute('tabindex'))->toBe('0')
            ->and($html->text('//table/caption'))->toBe('Clientes con saldo')
            ->and(array_map(fn (DOMElement $th): string => $th->getAttribute('scope'), $html->all('//thead//th')))->toBe(['col', 'col', 'col']);
    });

    it('keeps a hidden caption for screen readers', function () {
        $html = Html::parse((string) $this->blade('<x-ui.table caption="Clientes" :caption-hidden="true" :columns="$columns" />', ['columns' => clientColumns()]));

        expect($html->text('//table/caption'))->toBe('Clientes');
    });

    it('links sortable headers to the current URL with the next direction', function () {
        onPage('/cartera/clientes?estado=vencida&page=3&sort=name&direction=asc');

        $html = Html::parse((string) $this->blade(
            '<x-ui.table caption="Clientes" :columns="$columns" sort-by="name" sort-direction="asc" />',
            ['columns' => clientColumns()],
        ));

        $headers = $html->all('//thead//th');
        $links = $html->all('//thead//a');

        expect($links)->toHaveCount(2)
            ->and(queryOf($links[0]->getAttribute('href')))->toBe(['estado' => 'vencida', 'sort' => 'name', 'direction' => 'desc'])
            ->and(queryOf($links[1]->getAttribute('href')))->toBe(['estado' => 'vencida', 'sort' => 'overdue', 'direction' => 'asc'])
            ->and(parse_url($links[0]->getAttribute('href'), PHP_URL_PATH))->toBe('/cartera/clientes')
            ->and($headers[0]->getAttribute('aria-sort'))->toBe('ascending')
            ->and($headers[1]->hasAttribute('aria-sort'))->toBeFalse()
            ->and(Html::clean($headers[2]->textContent))->toBe('Estado');
    });

    it('explains what each sortable header does', function () {
        onPage('/cartera/clientes');

        $html = Html::parse((string) $this->blade(
            '<x-ui.table caption="Clientes" :columns="$columns" sort-by="overdue" sort-direction="desc" />',
            ['columns' => clientColumns()],
        ));

        $links = $html->all('//thead//a');

        expect(Html::clean($links[0]->textContent))->toBe('Ordenar por Cliente, ascendente')
            ->and(Html::clean($links[1]->textContent))->toBe('Ordenar por Saldo vencido, ascendente')
            ->and($html->all('//thead//th')[1]->getAttribute('aria-sort'))->toBe('descending');
    });

    it('renders the body rows and the footer', function () {
        $html = Html::parse((string) $this->blade(<<<'BLADE'
            <x-ui.table caption="Clientes" :columns="$columns">
                <tr><td>Ferretería El Tornillo</td><td>$ 1.200.000</td><td>Vencida</td></tr>
                <x-slot:footer>
                    <tr><th scope="row">Total</th><td>$ 1.200.000</td><td></td></tr>
                </x-slot:footer>
            </x-ui.table>
            BLADE, ['columns' => clientColumns()]));

        expect($html->count('//tbody/tr'))->toBe(1)
            ->and($html->text('//tbody/tr/td[1]'))->toBe('Ferretería El Tornillo')
            ->and($html->text('//tfoot/tr/th'))->toBe('Total')
            ->and($html->text('//table'))->not->toContain('No hay registros');
    });

    it('shows one row spanning every column when there are no rows', function () {
        $default = Html::parse((string) $this->blade('<x-ui.table caption="Clientes" :columns="$columns"></x-ui.table>', ['columns' => clientColumns()]));
        $custom = Html::parse((string) $this->blade(
            '<x-ui.table caption="Clientes" :columns="$columns" empty-message="Ningún cliente coincide con los filtros." />',
            ['columns' => clientColumns()],
        ));

        expect($default->one('//tbody/tr/td')->getAttribute('colspan'))->toBe('3')
            ->and($default->text('//tbody'))->toBe('No hay registros para mostrar.')
            ->and($custom->text('//tbody'))->toBe('Ningún cliente coincide con los filtros.');
    });

    it('needs a caption and valid columns', function (string $template) {
        expect(fn () => $this->blade($template, ['columns' => [['key' => 'name']]]))->toThrow(ViewException::class);
    })->with([
        'caption' => '<x-ui.table />',
        'column label' => '<x-ui.table caption="Clientes" :columns="$columns" />',
        'direction' => '<x-ui.table caption="Clientes" sort-direction="arriba" />',
    ]);
});

describe('<x-ui.pagination>', function () {
    it('renders nothing when everything fits in one page', function () {
        $paginator = new LengthAwarePaginator(['a', 'b'], total: 2, perPage: 25, currentPage: 1);

        expect(trim((string) $this->blade('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $paginator])))->toBe('');
    });

    it('links to the previous and next pages and says where you are', function () {
        $paginator = new LengthAwarePaginator(range(1, 25), total: 70, perPage: 25, currentPage: 2, options: ['path' => '/cartera/clientes']);

        $html = Html::parse((string) $this->blade('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $paginator]));

        $nav = $html->one('//nav');
        $previous = $html->one('//nav//a[@rel="prev"]');
        $next = $html->one('//nav//a[@rel="next"]');

        expect($nav->getAttribute('aria-label'))->toBe('Paginación')
            ->and(Html::clean($previous->textContent))->toBe('Anterior')
            ->and($previous->getAttribute('href'))->toBe('/cartera/clientes?page=1')
            ->and(Html::clean($next->textContent))->toBe('Siguiente')
            ->and($next->getAttribute('href'))->toBe('/cartera/clientes?page=3')
            ->and(Html::clean($nav->textContent))->toContain('Página 2 de 3');
    });

    it('does not link before the first page or after the last one', function () {
        $first = new LengthAwarePaginator(range(1, 25), total: 70, perPage: 25, currentPage: 1);
        $last = new LengthAwarePaginator(range(1, 20), total: 70, perPage: 25, currentPage: 3);

        $firstHtml = Html::parse((string) $this->blade('<x-ui.pagination :paginator="$p" label="Páginas de clientes" />', ['p' => $first]));
        $lastHtml = Html::parse((string) $this->blade('<x-ui.pagination :paginator="$p" />', ['p' => $last]));

        expect($firstHtml->one('//nav')->getAttribute('aria-label'))->toBe('Páginas de clientes')
            ->and($firstHtml->count('//a[@rel="prev"]'))->toBe(0)
            ->and($firstHtml->text('//nav'))->toStartWith('Anterior')
            ->and($lastHtml->count('//a[@rel="next"]'))->toBe(0)
            ->and($lastHtml->text('//nav'))->toEndWith('Siguiente');
    });

    it('keeps the search, filters and sort of the current page in its links', function () {
        onPage('/cartera/clientes?search=ferre&estados[]=vencida&estados[]=juridico&sort=overdue&direction=desc&page=2');
        $paginator = new LengthAwarePaginator(range(1, 25), total: 70, perPage: 25, currentPage: 2, options: ['path' => '/cartera/clientes']);

        $html = Html::parse((string) $this->blade('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $paginator]));

        $previous = $html->one('//nav//a[@rel="prev"]')->getAttribute('href');
        $next = $html->one('//nav//a[@rel="next"]')->getAttribute('href');
        $kept = ['search' => 'ferre', 'estados' => ['vencida', 'juridico'], 'sort' => 'overdue', 'direction' => 'desc'];

        expect(queryOf($next))->toBe([...$kept, 'page' => '3'])
            ->and(queryOf($previous))->toBe([...$kept, 'page' => '1'])
            ->and(substr_count($next, 'page='))->toBe(1)
            ->and(parse_url($next, PHP_URL_PATH))->toBe('/cartera/clientes');
    });

    it('keeps the query under a custom page name', function () {
        onPage('/cartera/clientes?search=ferre&clientes_page=1');
        $paginator = new LengthAwarePaginator(range(1, 25), total: 70, perPage: 25, currentPage: 1, options: ['path' => '/cartera/clientes', 'pageName' => 'clientes_page']);

        $next = Html::parse((string) $this->blade('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $paginator]))
            ->one('//nav//a[@rel="next"]')
            ->getAttribute('href');

        expect(queryOf($next))->toBe(['search' => 'ferre', 'clientes_page' => '2'])
            ->and(substr_count($next, 'page='))->toBe(1);
    });

    it('works with a simple paginator, without the page count', function () {
        $paginator = new Paginator(range(1, 26), perPage: 25, currentPage: 1);

        $html = Html::parse((string) $this->blade('<x-ui.pagination :paginator="$paginator" />', ['paginator' => $paginator]));

        expect($html->count('//a[@rel="next"]'))->toBe(1)
            ->and($html->text('//nav'))->not->toContain('Página');
    });

    it('needs a paginator', function () {
        expect(fn () => $this->blade('<x-ui.pagination :paginator="[]" />'))->toThrow(ViewException::class, 'Paginator');
    });
});
