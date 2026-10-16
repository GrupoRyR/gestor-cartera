<?php

use App\Modules\Comisiones\Domain\Calculation\Alert;
use App\Modules\Comisiones\Domain\Calculation\AlertCollector;
use App\Modules\Comisiones\Domain\Calculation\AlertKind;
use App\Modules\Comisiones\Domain\Calculation\AlertSeverity;

describe('AlertKind', function () {
    it('labels each kind in Spanish and knows whether it blocks the approval', function (AlertKind $kind, string $value, string $label, AlertSeverity $severity) {
        expect($kind->value)->toBe($value)
            ->and($kind->label())->toBe($label)
            ->and($kind->severity())->toBe($severity);
    })->with([
        'unknown code' => [AlertKind::UnknownSellerCode, 'codigo_desconocido', 'Código de vendedor desconocido', AlertSeverity::Blocking],
        'client without seller' => [AlertKind::ClientWithoutSeller, 'cliente_sin_vendedor', 'Cliente sin vendedor asignado', AlertSeverity::Blocking],
        'missing budget' => [AlertKind::MissingBudget, 'sin_presupuesto', 'Sin presupuesto', AlertSeverity::Blocking],
        'negative commission' => [AlertKind::NegativeCommission, 'comision_negativa', 'Comisión negativa', AlertSeverity::Blocking],
        'duplicate line' => [AlertKind::DuplicateLine, 'linea_duplicada', 'Línea duplicada', AlertSeverity::Blocking],
        'crossing without base' => [AlertKind::CrossingWithoutBase, 'cruce_sin_base', 'Cruce sin base sin IVA', AlertSeverity::Blocking],
        'payment before invoice' => [AlertKind::PaymentBeforeInvoice, 'pago_antes_de_factura', 'Pago con fecha anterior a la factura', AlertSeverity::Warning],
        'annulled collection' => [AlertKind::AnnulledCollection, 'recaudo_anulado', 'Recaudo anulado', AlertSeverity::Warning],
        'foreign currency' => [AlertKind::ForeignCurrency, 'moneda_extranjera', 'Moneda extranjera', AlertSeverity::Warning],
    ]);

    it('labels each severity in Spanish', function () {
        expect(AlertSeverity::Blocking->label())->toBe('Bloqueante')
            ->and(AlertSeverity::Warning->label())->toBe('Advertencia');
    });
});

describe('Alert', function () {
    it('has a stable key made of its kind and reference', function () {
        $alert = new Alert(AlertKind::UnknownSellerCode, '99', 'El código 99 no existe.', 99);

        expect($alert->key())->toBe('codigo_desconocido:99')
            ->and($alert->severity())->toBe(AlertSeverity::Blocking)
            ->and($alert->isBlocking())->toBeTrue()
            ->and($alert->sellerCode)->toBe(99);
    });

    it('does not block on a warning', function () {
        expect((new Alert(AlertKind::ForeignCurrency, 'F7-1', 'Moneda extranjera.'))->isBlocking())->toBeFalse();
    });

    it('rejects a blank reference or message', function (string $reference, string $message) {
        new Alert(AlertKind::DuplicateLine, $reference, $message);
    })->throws(InvalidArgumentException::class)->with([
        'blank reference' => [' ', 'Línea repetida.'],
        'blank message' => ['F1-1', ''],
    ]);
});

describe('AlertCollector', function () {
    it('keeps the first alert of each key, in order', function () {
        $alerts = new AlertCollector;
        $alerts->add(new Alert(AlertKind::DuplicateLine, 'F1-1', 'Primera.'));
        $alerts->add(new Alert(AlertKind::UnknownSellerCode, '99', 'Código 99.'));
        $alerts->add(new Alert(AlertKind::DuplicateLine, 'F1-1', 'Segunda.'));
        $alerts->add(new Alert(AlertKind::MissingBudget, 'F1-1', 'Otra clase, misma referencia.'));

        expect(array_map(fn (Alert $alert) => $alert->key(), $alerts->all()))
            ->toBe(['linea_duplicada:F1-1', 'codigo_desconocido:99', 'sin_presupuesto:F1-1'])
            ->and($alerts->all()[0]->message)->toBe('Primera.');
    });
});
