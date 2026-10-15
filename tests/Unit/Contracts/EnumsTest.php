<?php

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Contracts\Enums\LoadStatus;
use App\Modules\Core\Contracts\Enums\Role;

/*
| Stored values are part of the frozen contract: databases, URLs and the robot
| use them. Labels are the Spanish user-facing text.
*/
dataset('contract enums', [
    'CompanyCode' => [CompanyCode::class, [
        'SGX' => 'Segurex Latam',
        'SDC' => 'Soluciones y Derivados',
    ]],
    'Role' => [Role::class, [
        'admin' => 'Administrador',
        'gerente_cartera' => 'Gerente de cartera',
        'analista_cartera' => 'Analista de cartera',
        'asesor' => 'Asesor comercial',
        'gerente_comercial' => 'Gerente comercial',
        'tesoreria' => 'Tesorería',
    ]],
    'ClientStatus' => [ClientStatus::class, [
        'al_dia' => 'Al día',
        'por_vencer' => 'Por vencer',
        'vencida' => 'Vencida',
        'en_gestion' => 'En gestión',
        'compromiso' => 'Compromiso',
        'incumplido' => 'Incumplido',
        'pago_recibido' => 'Pago recibido',
        'juridico' => 'Jurídico',
    ]],
    'CommitmentStatus' => [CommitmentStatus::class, [
        'pendiente' => 'Pendiente',
        'cumplido' => 'Cumplido',
        'incumplido' => 'Incumplido',
        'anulado' => 'Anulado',
    ]],
    'ActivityType' => [ActivityType::class, [
        'llamada' => 'Llamada',
        'nota' => 'Nota',
        'compromiso' => 'Compromiso de pago',
        'descuento' => 'Descuento ofrecido',
        'correo_enviado' => 'Correo enviado',
        'correo_recibido' => 'Correo recibido',
        'cambio_estado' => 'Cambio de estado',
        'sistema' => 'Sistema',
    ]],
    'Dataset' => [Dataset::class, [
        'cartera' => 'Cartera',
        'asesores' => 'Asesores',
        'contactos' => 'Contactos',
        'ventas' => 'Ventas',
        'recaudos' => 'Recaudos',
        'cruces' => 'Cruces',
        'maestro_clientes' => 'Maestro de clientes',
        'maestro_vendedores' => 'Maestro de vendedores',
    ]],
    'LoadSource' => [LoadSource::class, [
        'robot' => 'Robot',
        'manual' => 'Carga manual',
    ]],
    'LoadStatus' => [LoadStatus::class, [
        'aceptada' => 'Aceptada',
        'rechazada' => 'Rechazada',
    ]],
    'Bank' => [Bank::class, [
        'bogota' => 'Banco de Bogotá',
        'davivienda' => 'Davivienda',
        'bancolombia' => 'Bancolombia',
        'miami' => 'Miami',
    ]],
]);

it('keeps the frozen values, labels and order as select options', function (string $enum, array $expected) {
    expect($enum::options())->toBe($expected);
})->with('contract enums');

it('labels every case in Spanish sentence case without sustained uppercase', function (string $enum) {
    foreach ($enum::cases() as $case) {
        expect($case->label())
            ->not->toBe('')
            ->toMatch('/^\p{Lu}/u')
            ->not->toMatch('/\p{Lu}{2,}/u');
    }
})->with('contract enums');

it('orders client statuses as the Kanban columns', function () {
    expect(ClientStatus::cases())->toBe([
        ClientStatus::Current,
        ClientStatus::DueSoon,
        ClientStatus::Overdue,
        ClientStatus::InProgress,
        ClientStatus::Committed,
        ClientStatus::Defaulted,
        ClientStatus::PaymentReceived,
        ClientStatus::Legal,
    ]);
});

it('uses the robot batch URL segment as the dataset value', function () {
    expect(Dataset::from('cartera'))->toBe(Dataset::Receivables)
        ->and(Dataset::from('maestro_vendedores'))->toBe(Dataset::SalesRepMaster)
        ->and(Dataset::tryFrom('inventario'))->toBeNull();
});
