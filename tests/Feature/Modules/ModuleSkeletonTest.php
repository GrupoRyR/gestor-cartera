<?php

use App\Modules\Bancos\Providers\BancosServiceProvider;
use App\Modules\Core\Providers\CoreServiceProvider;
use App\Support\Modules\ModuleRegistry;

it('discovers the eight modules with Core first', function () {
    expect(app(ModuleRegistry::class)->names())
        ->toBe(['Core', 'Asesores', 'Bancos', 'Cartera', 'Cobro', 'Comisiones', 'Gerencia', 'Ingesta']);
});

it('registers the service provider of every module', function () {
    foreach (app(ModuleRegistry::class)->all() as $module) {
        expect($this->app->getProvider($module->providerClass()))->not->toBeNull();
    }
});

it('serves Core at the site root and the Bancos API directly under /api', function () {
    $core = $this->app->getProvider(CoreServiceProvider::class);
    $bancos = $this->app->getProvider(BancosServiceProvider::class);

    expect((fn (): string => $this->webPrefix())->call($core))->toBe('')
        ->and((fn (): string => $this->apiPrefix())->call($bancos))->toBe('')
        ->and((fn (): string => $this->webPrefix())->call($bancos))->toBe('bancos');
});

it('seeds the database through every module permission seeder', function () {
    expect(fn () => $this->seed())->not->toThrow(Throwable::class);
});

it('exposes the env-backed module settings', function () {
    expect(config('cobro.mailboxes'))->toHaveKeys(['SGX', 'SDC'])
        ->and(config('cobro.graph'))->toHaveKeys(['tenant_id', 'client_id', 'client_secret'])
        ->and(config('ingesta'))->toHaveKeys(['hmac_keys', 'hmac_max_skew', 'alert_deadline', 'alert_recipients'])
        ->and(config('ingesta.hmac_max_skew'))->toBeInt()
        ->and(config('bancos'))->toHaveKeys(['import_path', 'api_token']);
});

it('reads the ingestion alert recipients as a trimmed list without blanks', function () {
    $_SERVER['INGESTA_ALERT_RECIPIENTS'] = ' ti@segurex.co , ,gerente@segurex.co,';

    try {
        $config = require app_path('Modules/Ingesta/config/ingesta.php');
    } finally {
        unset($_SERVER['INGESTA_ALERT_RECIPIENTS']);
    }

    expect($config['alert_recipients'])->toBe(['ti@segurex.co', 'gerente@segurex.co']);
});
