<?php

namespace App\Modules\Asesores\Domain\Whatsapp;

use App\Modules\Core\Contracts\Data\Invoice;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;

/**
 * The account statement an advisor sends a client by WhatsApp, in Spanish and
 * addressed to the client ("usted"): the balance, what is overdue and the
 * invoices, the oldest first.
 *
 * Only documents the client owes count (a positive balance): credit notes and
 * receipts would lower the total but have no line, and a total that does not
 * match its lines raises questions. The client file shows the net figures.
 */
final class AccountMessage
{
    /**
     * @param  list<Invoice>  $invoices  The open documents of the client.
     */
    public static function build(string $client, string $company, CarbonImmutable $date, array $invoices, int $maxLines = 12): string
    {
        $owed = array_values(array_filter($invoices, fn (Invoice $invoice): bool => $invoice->balance > 0));
        $today = DateFormat::date($date);

        if ($owed === []) {
            return self::plain(implode("\n", [
                "Buen día, {$client}.",
                '',
                "Le confirmo que al {$today} su cuenta con {$company} no tiene saldo pendiente. ¡Gracias!",
            ]));
        }

        // The oldest first, and the earlier due date among equals.
        usort($owed, fn (Invoice $a, Invoice $b): int => $b->daysOverdue <=> $a->daysOverdue ?: $a->dueAt <=> $b->dueAt ?: $a->id <=> $b->id);

        $total = 0;
        $overdue = 0;

        foreach ($owed as $invoice) {
            $cents = (int) round($invoice->balance * 100);
            $total += $cents;
            $overdue += $invoice->daysOverdue > 0 ? $cents : 0;
        }

        $lines = [
            "Buen día, {$client}.",
            '',
            "Le comparto el estado de su cuenta con {$company} al {$today}:",
            '',
            'Saldo total: '.Money::format($total / 100),
            $overdue > 0 ? 'Vencido: '.Money::format($overdue / 100) : 'Sin saldo vencido.',
            '',
        ];

        foreach (array_slice($owed, 0, $maxLines) as $invoice) {
            $lines[] = self::line($invoice);
        }

        $more = count($owed) - $maxLines;

        if ($more > 0) {
            $lines[] = $more === 1 ? '• y 1 factura más' : "• y {$more} facturas más";
        }

        $lines[] = '';
        $lines[] = 'Quedo atento(a) para coordinar el pago. ¡Gracias!';

        return self::plain(implode("\n", $lines));
    }

    private static function line(Invoice $invoice): string
    {
        $line = "• Factura {$invoice->documentNumber} · vence ".DateFormat::date($invoice->dueAt).' · '.Money::format($invoice->balance);

        if ($invoice->daysOverdue > 0) {
            $line .= $invoice->daysOverdue === 1 ? ' (1 día de mora)' : " ({$invoice->daysOverdue} días de mora)";
        }

        return $line;
    }

    /**
     * Money uses a no-break space; a chat message does not need it.
     */
    private static function plain(string $text): string
    {
        return str_replace("\u{00A0}", ' ', $text);
    }
}
