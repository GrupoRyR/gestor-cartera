<?php

use App\Modules\Core\Contracts\Enums\ClientStatus;
use Carbon\CarbonImmutable;
use Illuminate\View\ViewException;
use Tests\Feature\Design\Support\Html;

describe('<x-ui.money>', function () {
    it('shows pesos in Colombian format', function () {
        $html = Html::parse((string) $this->blade('<x-ui.money :value="1234567" />'));

        expect($html->text('//span'))->toBe("\$\u{00A0}1.234.567")
            ->and($html->count('//*[@data-negative]'))->toBe(0);
    });

    it('marks negative amounts', function () {
        $html = Html::parse((string) $this->blade('<x-ui.money :value="-250000" />'));

        expect($html->text('//*[@data-negative]'))->toBe("-\$\u{00A0}250.000");
    });

    it('shows dollars with two decimals', function () {
        $html = Html::parse((string) $this->blade('<x-ui.money :value="15.5" currency="USD" />'));

        expect($html->text('//span'))->toBe("US\$\u{00A0}15,50");
    });

    it('shows the fallback for empty amounts', function () {
        $default = Html::parse((string) $this->blade('<x-ui.money :value="null" />'));
        $custom = Html::parse((string) $this->blade('<x-ui.money :value="null" fallback="Sin saldo" />'));

        expect($default->text('//span'))->toBe('—')
            ->and($custom->text('//span'))->toBe('Sin saldo')
            ->and($default->count('//*[@data-negative]'))->toBe(0);
    });

    it('passes extra attributes through', function () {
        $html = Html::parse((string) $this->blade('<x-ui.money :value="1" data-total="si" />'));

        expect($html->count('//span[@data-total="si"]'))->toBe(1);
    });
});

describe('<x-ui.date>', function () {
    it('shows the date in a time element with a machine-readable value', function () {
        $html = Html::parse((string) $this->blade('<x-ui.date value="2026-10-15" />'));

        $time = $html->one('//time');

        expect($time->getAttribute('datetime'))->toBe('2026-10-15')
            ->and(Html::clean($time->textContent))->toBe('15/10/2026');
    });

    it('shows date and time in Colombian time', function () {
        $html = Html::parse((string) $this->blade(
            '<x-ui.date :value="$fecha" :time="true" />',
            ['fecha' => CarbonImmutable::parse('2026-10-15 03:00', 'UTC')],
        ));

        $time = $html->one('//time');

        expect($time->getAttribute('datetime'))->toBe('2026-10-14T22:00:00-05:00')
            ->and(Html::clean($time->textContent))->toBe('14/10/2026 22:00');
    });

    it('shows the fallback without a time element when there is no date', function () {
        $html = Html::parse((string) $this->blade('<x-ui.date :value="null" fallback="Sin fecha" />'));

        expect($html->count('//time'))->toBe(0)
            ->and($html->text('//body'))->toBe('Sin fecha');
    });
});

describe('<x-ui.badge-state>', function () {
    it('shows the Spanish label of every client status', function (ClientStatus $status) {
        $html = Html::parse((string) $this->blade('<x-ui.badge-state :state="$status" />', ['status' => $status]));

        expect($html->text("//*[@data-state='{$status->value}']"))->toBe($status->label());
    })->with(ClientStatus::cases());

    it('accepts the status value as a string', function () {
        $html = Html::parse((string) $this->blade('<x-ui.badge-state state="en_gestion" />'));

        expect($html->text("//*[@data-state='en_gestion']"))->toBe('En gestión');
    });

    it('fails loudly on an unknown status', function () {
        expect(fn () => $this->blade('<x-ui.badge-state state="moroso" />'))
            ->toThrow(ViewException::class, 'moroso');
    });
});

describe('<x-ui.icon>', function () {
    it('hides decorative icons from assistive technology', function () {
        $icon = Html::parse((string) $this->blade('<x-ui.icon name="search" />'))->one('//*[@data-icon="search"]');

        expect($icon->getAttribute('aria-hidden'))->toBe('true')
            ->and($icon->hasAttribute('role'))->toBeFalse();
    });

    it('announces labelled icons as images', function () {
        $icon = Html::parse((string) $this->blade('<x-ui.icon name="warning" label="Atención" />'))->one('//*[@data-icon="warning"]');

        expect($icon->getAttribute('role'))->toBe('img')
            ->and($icon->getAttribute('aria-label'))->toBe('Atención')
            ->and($icon->hasAttribute('aria-hidden'))->toBeFalse();
    });

    it('needs a name', function () {
        expect(fn () => $this->blade('<x-ui.icon />'))->toThrow(ViewException::class, '$name');
    });
});
