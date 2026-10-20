<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Templates\Placeholders;
use App\Modules\Cobro\Domain\Templates\SpanishList;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;

/**
 * Renders a collection mail: fills the template placeholders with the
 * client's data, splits the texts in paragraphs (blank lines) and renders
 * the mail view. Texts stay plain: html() escapes them and only turns
 * **text** into bold, so a value can never inject HTML.
 */
final class NoticeRenderer
{
    /**
     * @param  string  $view  Mail view, e.g. cobro::correos.cobro-semanal.
     * @param  bool  $test  A test send: "[Prueba]" subject and banner.
     * @param  bool  $draft  A provisional format: "[Borrador]" subject and banner.
     */
    public function render(string $view, Company $company, NoticeData $data, TemplateContent $template, bool $test = false, bool $draft = false): RenderedMail
    {
        $values = self::values($company, $data);
        $subject = trim((string) preg_replace('/\s+/u', ' ', str_replace('**', '', Placeholders::fill($template->subject, $values))));

        if ($draft) {
            $subject = (string) __('cobro::correos.borrador_asunto', ['asunto' => $subject]);
        }

        if ($test) {
            $subject = (string) __('cobro::correos.prueba_asunto', ['asunto' => $subject]);
        }

        $intro = self::paragraphs(Placeholders::fill($template->intro, $values));
        $closing = self::paragraphs(Placeholders::fill($template->closing, $values));

        $html = view($view, [
            'company' => $company,
            'data' => $data,
            'subject' => $subject,
            // Plain-text paragraphs (the view escapes them) and the same as
            // safe HTML with **bold** applied; a view uses one or the other.
            'intro' => array_map(fn (string $paragraph): string => str_replace('**', '', $paragraph), $intro),
            'closing' => array_map(fn (string $paragraph): string => str_replace('**', '', $paragraph), $closing),
            'introHtml' => array_map(self::html(...), $intro),
            'closingHtml' => array_map(self::html(...), $closing),
            'test' => $test,
            'draft' => $draft,
        ])->render();

        return new RenderedMail($subject, $html);
    }

    /**
     * The value of each placeholder for this client.
     *
     * @return array<string, string>
     */
    public static function values(Company $company, NoticeData $data): array
    {
        $overdue = $data->overdueInvoices();

        return [
            'cliente' => $data->clientName,
            'nit' => $data->taxId,
            'empresa' => $company->name,
            'total_vencido' => Money::format($data->overdue()),
            'facturas' => (string) count($overdue),
            'factura_o_facturas' => (string) __(count($overdue) === 1 ? 'cobro::correos.la_factura' : 'cobro::correos.las_facturas'),
            'numeros_facturas' => SpanishList::join(array_map(fn (Invoice $invoice): string => $invoice->documentNumber, $overdue)),
            'vencimientos' => SpanishList::join(array_values(array_unique(array_map(fn (Invoice $invoice): string => DateFormat::date($invoice->dueAt), $overdue)))),
            'fecha_corte' => $data->cutoffDate === null ? '—' : DateFormat::date($data->cutoffDate),
        ];
    }

    /**
     * Paragraphs separated by blank lines, each trimmed; line breaks inside
     * a paragraph stay.
     *
     * @return list<string>
     */
    private static function paragraphs(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return array_values(array_filter(
            array_map('trim', preg_split('/\n\s*\n/u', $text) ?: []),
            fn (string $paragraph): bool => $paragraph !== '',
        ));
    }

    /**
     * A plain-text paragraph as safe HTML: escaped first, then line breaks
     * as <br> and **text** as bold.
     */
    private static function html(string $paragraph): string
    {
        return (string) preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', nl2br(e($paragraph), false));
    }
}
