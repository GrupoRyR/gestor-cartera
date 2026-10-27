<?php

namespace App\Modules\Cobro\Infrastructure\Templates;

use App\Modules\Cobro\Domain\Runs\RunKind;
use App\Modules\Cobro\Domain\Templates\TemplateContent;
use App\Modules\Cobro\Infrastructure\Persistence\Template;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;

/**
 * The texts of each company's mail: the saved ones (cobro_templates) or
 * the defaults of cobro::plantillas.por_defecto. Saving and restoring the
 * defaults go to the audit log ("cobro.template.updated|reset"); when the
 * texts really change, SendTemplateChangeNotice mails the differences to
 * IT and internal control (audit S-08).
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
            ? self::default($kind, $company)
            : new TemplateContent($saved->subject, $saved->intro, $saved->closing);
    }

    /**
     * The default texts of a mail: the company's own
     * (cobro::plantillas.por_defecto.<kind>.<company>) when it has them,
     * else the ones shared by every company (…por_defecto.<kind>).
     */
    public static function default(RunKind $kind, CompanyCode $company): TemplateContent
    {
        $key = "cobro::plantillas.por_defecto.{$kind->value}";

        if (Lang::has("{$key}.{$company->value}.asunto")) {
            $key .= ".{$company->value}";
        }

        return new TemplateContent(
            subject: (string) __("{$key}.asunto"),
            intro: (string) __("{$key}.inicio"),
            closing: (string) __("{$key}.cierre"),
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

        $changes = self::changes($before, $content);

        $this->audit->record('cobro.template.updated', 'cobro_template', $template->id, $changes, [
            'company' => $company->value,
            'kind' => $kind->value,
        ]);

        $this->notify($company, $kind, SendTemplateChangeNotice::UPDATED, $userId, $changes);
    }

    public function reset(CompanyCode $company, RunKind $kind): void
    {
        $template = Template::query()->where('company', $company)->where('kind', $kind)->first();

        if ($template === null) {
            return;
        }

        $changes = self::changes(
            new TemplateContent($template->subject, $template->intro, $template->closing),
            self::default($kind, $company),
        );

        $template->delete();

        $this->audit->record('cobro.template.reset', 'cobro_template', $template->id, [], [
            'company' => $company->value,
            'kind' => $kind->value,
        ]);

        $userId = Auth::id();
        $this->notify($company, $kind, SendTemplateChangeNotice::RESET, is_numeric($userId) ? (int) $userId : null, $changes);
    }

    /**
     * The fields whose text differs, with the old and the new text.
     *
     * @return array<string, array{old: string, new: string}>
     */
    private static function changes(TemplateContent $before, TemplateContent $after): array
    {
        $changes = [];

        foreach (['subject', 'intro', 'closing'] as $field) {
            if ($before->{$field} !== $after->{$field}) {
                $changes[$field] = ['old' => $before->{$field}, 'new' => $after->{$field}];
            }
        }

        return $changes;
    }

    /**
     * Queues the change notice once the change is committed; a save that
     * leaves every text as it was sends none.
     *
     * @param  array<string, array{old: string, new: string}>  $changes
     */
    private function notify(CompanyCode $company, RunKind $kind, string $change, ?int $userId, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        SendTemplateChangeNotice::dispatch($company, $kind, $change, $userId, CarbonImmutable::now()->toIso8601String(), $changes)
            ->afterCommit();
    }
}
