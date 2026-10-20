<?php

namespace App\Modules\Cobro\Infrastructure\Templates;

use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The texts of each company's mail: the saved ones (cobro_templates) or
 * the defaults of cobro::plantillas.por_defecto. Saving and restoring the
 * defaults go to the audit log ("cobro.template.updated|reset").
 */
final class TemplateStore
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function for(CompanyCode $company, RunKind $kind): TemplateContent
    {
        $saved = Template::query()->where('company', $company)->where('kind', $kind)->first();

        return $saved === null
            ? self::default($kind)
            : new TemplateContent($saved->subject, $saved->intro, $saved->closing);
    }

    public static function default(RunKind $kind): TemplateContent
    {
        return new TemplateContent(
            subject: (string) __("cobro::plantillas.por_defecto.{$kind->value}.asunto"),
            intro: (string) __("cobro::plantillas.por_defecto.{$kind->value}.inicio"),
            closing: (string) __("cobro::plantillas.por_defecto.{$kind->value}.cierre"),
            isDefault: true,
        );
    }

    public function save(CompanyCode $company, RunKind $kind, TemplateContent $content, ?int $userId): void
    {
        $before = $this->for($company, $kind);

        $template = Template::query()->firstOrNew(['company' => $company, 'kind' => $kind]);
        $template->fill([
            'subject' => $content->subject,
            'intro' => $content->intro,
            'closing' => $content->closing,
            'updated_by' => $userId,
        ])->save();

        $changes = [];

        foreach (['subject', 'intro', 'closing'] as $field) {
            if ($before->{$field} !== $content->{$field}) {
                $changes[$field] = ['old' => $before->{$field}, 'new' => $content->{$field}];
            }
        }

        $this->audit->record('cobro.template.updated', 'cobro_template', $template->id, $changes, [
            'company' => $company->value,
            'kind' => $kind->value,
        ]);
    }

    public function reset(CompanyCode $company, RunKind $kind): void
    {
        $template = Template::query()->where('company', $company)->where('kind', $kind)->first();

        if ($template === null) {
            return;
        }

        $template->delete();

        $this->audit->record('cobro.template.reset', 'cobro_template', $template->id, [], [
            'company' => $company->value,
            'kind' => $kind->value,
        ]);
    }
}
