<?php

namespace App\Modules\Asesores\Domain\Portfolio;

use App\Modules\Core\Contracts\Data\Advisor;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * Names one SAP advisor in a URL or a form: "SGX-12". SAP codes repeat across
 * companies, so the code alone would mix two people.
 */
final readonly class AdvisorKey
{
    public function __construct(
        public CompanyCode $company,
        public int $code,
    ) {}

    /**
     * The key written in $value, or null for anything that is not a company
     * code, a dash and a number.
     */
    public static function parse(?string $value): ?self
    {
        if ($value === null || preg_match('/^(SGX|SDC)-(\d{1,9})$/D', $value, $parts) !== 1) {
            return null;
        }

        // The pattern only lets the two company codes through.
        return new self(CompanyCode::from($parts[1]), (int) $parts[2]);
    }

    public static function of(Advisor $advisor): self
    {
        return new self($advisor->company, $advisor->code);
    }

    public function value(): string
    {
        return $this->company->value.'-'.$this->code;
    }

    public function matches(Advisor $advisor): bool
    {
        return $advisor->company === $this->company && $advisor->code === $this->code;
    }
}
