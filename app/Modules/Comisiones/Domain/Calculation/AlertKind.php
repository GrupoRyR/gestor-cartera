<?php

namespace App\Modules\Comisiones\Domain\Calculation;

/**
 * What the settlement found that someone must look at (§9.6). Blocking kinds
 * stop the approval until they are justified (§9.7).
 */
enum AlertKind: string
{
    /** A seller code without parameters in the period, or a line without code (§4, §5.1). */
    case UnknownSellerCode = 'codigo_desconocido';

    /** A crossing whose client had no seller on the date (§5.4). */
    case ClientWithoutSeller = 'cliente_sin_vendedor';

    /** A code with a minimum compliance, sales and no budget (§5.2). */
    case MissingBudget = 'sin_presupuesto';

    /**
     * A negative commission (§9.6): a code's sale, collection or total
     * commission (reference "14"), a derived commission (its key, "81.1") or
     * a director team's sales part, collection part or total (its key,
     * "distribucion"). Derived and team keys are never plain numbers, so the
     * three references never collide.
     */
    case NegativeCommission = 'comision_negativa';

    /** The same natural key twice in one load (§9.5). */
    case DuplicateLine = 'linea_duplicada';

    /** A crossing without any way to know its amount without VAT (§5.4). */
    case CrossingWithoutBase = 'cruce_sin_base';

    /** A payment dated before its invoice; it counts as 0 days (§5.3). */
    case PaymentBeforeInvoice = 'pago_antes_de_factura';

    /** An annulled payment or crossing in the period, left out (§9.6). */
    case AnnulledCollection = 'recaudo_anulado';

    /** A line in another currency or with an exchange rate other than 1 (§14.7). */
    case ForeignCurrency = 'moneda_extranjera';

    public function label(): string
    {
        return match ($this) {
            self::UnknownSellerCode => 'Código de vendedor desconocido',
            self::ClientWithoutSeller => 'Cliente sin vendedor asignado',
            self::MissingBudget => 'Sin presupuesto',
            self::NegativeCommission => 'Comisión negativa',
            self::DuplicateLine => 'Línea duplicada',
            self::CrossingWithoutBase => 'Cruce sin base sin IVA',
            self::PaymentBeforeInvoice => 'Pago con fecha anterior a la factura',
            self::AnnulledCollection => 'Recaudo anulado',
            self::ForeignCurrency => 'Moneda extranjera',
        };
    }

    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::PaymentBeforeInvoice, self::AnnulledCollection, self::ForeignCurrency => AlertSeverity::Warning,
            default => AlertSeverity::Blocking,
        };
    }
}
