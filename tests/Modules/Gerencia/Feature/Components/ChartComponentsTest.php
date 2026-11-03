<?php

use App\Modules\Gerencia\Http\Presenters\ChartScale;
use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

/**
 * A line-chart series of percent points with the same value in every month.
 *
 * @return array{name: string, tone: string, points: list<array{label: string, value: float, text: string}>}
 */
function flatSeries(string $name, string $tone, float $value): array
{
    return [
        'name' => $name,
        'tone' => $tone,
        'points' => array_map(fn (string $label): array => ['label' => $label, 'value' => $value, 'text' => "{$value} %"], ['ago 26', 'sep 26', 'oct 26']),
    ];
}

describe('line chart', function () {
    it('keeps the end labels of lines that end together apart and inside the drawing', function (float $value) {
        $series = [
            flatSeries('Grupo RYR', 'group', $value),
            flatSeries('Segurex Latam', 'sgx', $value),
            flatSeries('Soluciones y Derivados', 'sdc', $value),
        ];
        // A second, higher series puts the shared value on the top or the bottom of the axis.
        $series[] = flatSeries('Referencia', 'sgx', $value === 0.0 ? 40.0 : 0.0);

        $html = Html::parse((string) $this->blade('<x-gerencia::line-chart :series="$series" label="Prueba" />', ['series' => $series]));
        [, , $width, $height] = array_map(floatval(...), explode(' ', $html->one('//svg')->getAttribute('viewbox')));
        $labels = $html->all("//text[@data-part='end-label']");
        $ys = array_map(fn ($label): float => (float) $label->getAttribute('y'), $labels);
        sort($ys);

        expect($labels)->toHaveCount(4);

        foreach ($labels as $label) {
            $x = (float) $label->getAttribute('x');
            $y = (float) $label->getAttribute('y');

            expect($x + ChartScale::labelWidth([$label->textContent]))->toBeLessThanOrEqual($width)
                ->and($y)->toBeGreaterThanOrEqual(6.0)
                ->and($y)->toBeLessThanOrEqual($height - 6.0);
        }

        foreach (array_slice($ys, 1) as $i => $y) {
            expect($y - $ys[$i])->toBeGreaterThanOrEqual(12.0);
        }
    })->with([
        'at the top' => [40.0],
        'at the bottom' => [0.0],
    ]);

    it('draws a lone series without legend or end label', function () {
        $series = [flatSeries('Segurex Latam', 'brand', 20.0)];

        $html = Html::parse((string) $this->blade('<x-gerencia::line-chart :series="$series" label="Prueba" />', ['series' => $series]));

        expect($html->one('//path')->getAttribute('class'))->toBe('stroke-brand-accent')
            ->and($html->one('//svg')->getAttribute('viewbox'))->toBe('0 0 480 260')
            ->and($html->count("//text[@data-part='end-label']"))->toBe(0)
            ->and($html->count("//ul[@data-part='legend']"))->toBe(0);
    });

    it('rejects a tone it does not know', function () {
        $series = [flatSeries('Grupo RYR', 'neutral', 20.0)];

        expect(fn () => $this->blade('<x-gerencia::line-chart :series="$series" label="Prueba" />', ['series' => $series]))
            ->toThrow(ViewException::class, 'Unknown line-chart tone [neutral]');
    });
});

describe('bar chart', function () {
    it('fills the bars with the brand accent or the group gray', function (string $tone, string $class) {
        $bars = [['label' => '10 oct', 'value' => 100.0, 'text' => '$ 100']];

        $html = Html::parse((string) $this->blade('<x-gerencia::bar-chart :bars="$bars" label="Prueba" :tone="$tone" />', ['bars' => $bars, 'tone' => $tone]));

        expect($html->one("//figure[@data-chart='bar']//path")->getAttribute('class'))->toBe($class);
    })->with([
        'a company' => ['brand', 'fill-brand-accent'],
        'the group' => ['group', 'fill-fg-muted'],
    ]);

    it('rejects a tone it does not know', function (string $tone) {
        $bars = [['label' => '10 oct', 'value' => 100.0, 'text' => '$ 100']];

        expect(fn () => $this->blade('<x-gerencia::bar-chart :bars="$bars" label="Prueba" :tone="$tone" />', ['bars' => $bars, 'tone' => $tone]))
            ->toThrow(ViewException::class, "Unknown bar-chart tone [{$tone}]");
    })->with(['neutral', 'sgx']);
});
