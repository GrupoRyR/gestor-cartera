<?php

namespace App\Modules\Cobro\Infrastructure\Mail;

use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Cobro\Domain\Templates\Placeholders;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Core\Contracts\Data\Company;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;

/**
 * Renders a collection mail: fills the template placeholders with the
 * client's data, splits the texts in paragraphs (blank lines) and renders
 * the mail view inside the company's brand frame. Texts stay plain: the
 * view escapes them.
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
        $values = [
            'cliente' => $data->clientName,
            'nit' => $data->taxId,
            'empresa' => $company->name,
            'total_vencido' => Money::format($data->overdue()),
            'facturas' => (string) $data->overdueCount(),
            'fecha_corte' => $data->cutoffDate === null ? '—' : DateFormat::date($data->cutoffDate),
        ];

        $subject = trim((string) preg_replace('/\s+/u', ' ', Placeholders::fill($template->subject, $values)));

        if ($draft) {
            $subject = (string) __('cobro::correos.borrador_asunto', ['asunto' => $subject]);
        }

        if ($test) {
            $subject = (string) __('cobro::correos.prueba_asunto', ['asunto' => $subject]);
        }

        $html = view($view, [
            'company' => $company,
            'data' => $data,
            'subject' => $subject,
            'intro' => self::paragraphs(Placeholders::fill($template->intro, $values)),
            'closing' => self::paragraphs(Placeholders::fill($template->closing, $values)),
            'test' => $test,
            'draft' => $draft,
        ])->render();

        return new RenderedMail($subject, $html);
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
}
