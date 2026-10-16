<?php

namespace App\Modules\Core\Infrastructure\Companies;

use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Company;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Domain\Status\StatusParameters;
use App\Modules\Core\Infrastructure\Persistence\CompanyRecord;

/**
 * Every company with the parameters admins edit, read once from "companies"
 * and kept until refresh(). A company without a row falls back to its seed
 * defaults (config('core.companies')) and its CompanyCode label.
 */
final class CompanyCatalog
{
    /**
     * @var array<string, array{company: Company, parameters: StatusParameters}>|null
     */
    private ?array $entries = null;

    /**
     * @return list<Company>
     */
    public function all(): array
    {
        return array_map($this->get(...), CompanyCode::cases());
    }

    public function get(CompanyCode $code): Company
    {
        return $this->entries()[$code->value]['company'];
    }

    public function parameters(CompanyCode $code): StatusParameters
    {
        return $this->entries()[$code->value]['parameters'];
    }

    /**
     * Forgets what was read, e.g. after an admin edits a company.
     */
    public function refresh(): void
    {
        $this->entries = null;
    }

    /**
     * @return array<string, array{company: Company, parameters: StatusParameters}>
     */
    private function entries(): array
    {
        if ($this->entries !== null) {
            return $this->entries;
        }

        $rows = CompanyRecord::query()->get()->keyBy(fn (CompanyRecord $row): string => $row->code->value);
        $entries = [];

        foreach (CompanyCode::cases() as $code) {
            $row = $rows->get($code->value);
            $graceDays = $row->grace_days ?? (int) config("core.companies.{$code->value}.grace_days");
            $dueSoonDays = $row->due_soon_days ?? (int) config("core.companies.{$code->value}.due_soon_days");

            $entries[$code->value] = [
                'company' => new Company(
                    code: $code,
                    name: $row->name ?? $code->label(),
                    graceDays: $graceDays,
                    palette: BrandPalette::official($code),
                ),
                'parameters' => new StatusParameters($graceDays, $dueSoonDays),
            ];
        }

        return $this->entries = $entries;
    }
}
