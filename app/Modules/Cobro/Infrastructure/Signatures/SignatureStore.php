<?php

namespace App\Modules\Cobro\Infrastructure\Signatures;

use App\Modules\Cobro\Domain\Signatures\SignaturePerson;
use App\Modules\Cobro\Infrastructure\Persistence\SignatureSetting;
use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * Who signs each company's Cobro mails: the saved person (cobro_firmas) or
 * the default of config cobro.firma.<company>. Saving and restoring the
 * default go to the audit log ("cobro.signature.updated|reset").
 */
final class SignatureStore
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function for(CompanyCode $company): SignaturePerson
    {
        $saved = SignatureSetting::query()->where('company', $company)->first();

        return $saved === null
            ? self::default($company)
            : SignaturePerson::fromArray($saved->only(['name', 'title', 'mobile', 'extension']));
    }

    public static function default(CompanyCode $company): SignaturePerson
    {
        return SignaturePerson::fromArray((array) config("cobro.firma.{$company->value}", []), isDefault: true);
    }

    public function save(CompanyCode $company, SignaturePerson $person, ?int $userId): void
    {
        $before = $this->for($company)->toArray();
        $after = $person->toArray();

        $row = SignatureSetting::query()->firstOrNew(['company' => $company]);
        $row->fill([...$after, 'updated_by' => $userId])->save();

        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = ['old' => $before[$field], 'new' => $value];
            }
        }

        $this->audit->record('cobro.signature.updated', 'cobro_firma', $row->id, $changes, ['company' => $company->value]);
    }

    /**
     * Back to the default person: deletes the saved row, if any.
     */
    public function reset(CompanyCode $company, ?int $userId): void
    {
        $saved = SignatureSetting::query()->where('company', $company)->first();

        if ($saved === null) {
            return;
        }

        $saved->delete();

        $this->audit->record('cobro.signature.reset', 'cobro_firma', $saved->id, $saved->only(['name', 'title', 'mobile', 'extension']), [
            'company' => $company->value,
            'user_id' => $userId,
        ]);
    }
}
