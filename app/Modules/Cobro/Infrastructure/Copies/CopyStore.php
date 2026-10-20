<?php

namespace App\Modules\Cobro\Infrastructure\Copies;

use App\Modules\Cobro\Domain\Copies\CopySettings;
use App\Modules\Cobro\Infrastructure\Persistence\CopySetting;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * The carbon copies of each company's weekly collection mail: the saved
 * ones (cobro_copias) or the defaults of config cobro.copias.<company>.
 * Saving and restoring the defaults go to the audit log
 * ("cobro.copies.updated|reset").
 */
final class CopyStore
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function for(CompanyCode $company): CopySettings
    {
        $saved = CopySetting::query()->where('company', $company)->first();

        return $saved === null
            ? self::default($company)
            : CopySettings::fromArray(['fixed' => $saved->fixed, 'advisor' => $saved->advisor, 'managers' => $saved->managers]);
    }

    public static function default(CompanyCode $company): CopySettings
    {
        return CopySettings::fromArray((array) config("cobro.copias.{$company->value}", []), isDefault: true);
    }

    public function save(CompanyCode $company, CopySettings $settings, ?int $userId): void
    {
        $before = $this->for($company)->toArray();
        $after = $settings->toArray();

        $row = CopySetting::query()->firstOrNew(['company' => $company]);
        $row->fill([...$after, 'updated_by' => $userId])->save();

        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['old' => $before[$field], 'new' => $value];
            }
        }

        $this->audit->record('cobro.copies.updated', 'cobro_copia', $row->id, $changes, ['company' => $company->value]);
    }

    /**
     * Back to the defaults: deletes the saved row, if any.
     */
    public function reset(CompanyCode $company, ?int $userId): void
    {
        $saved = CopySetting::query()->where('company', $company)->first();

        if ($saved === null) {
            return;
        }

        $saved->delete();

        $this->audit->record('cobro.copies.reset', 'cobro_copia', $saved->id, [
            'fixed' => $saved->fixed,
            'advisor' => $saved->advisor,
            'managers' => $saved->managers,
        ], ['company' => $company->value, 'user_id' => $userId]);
    }
}
