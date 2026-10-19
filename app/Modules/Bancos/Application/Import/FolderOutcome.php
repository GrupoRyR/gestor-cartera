<?php

namespace App\Modules\Bancos\Application\Import;

use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;

/**
 * What happened to one file of the import folder.
 */
final readonly class FolderOutcome
{
    private function __construct(
        public string $path,
        public ?CompanyCode $company,
        public ?Bank $bank,
        public ?ImportResult $result,
        public ?string $problem,
    ) {}

    public static function imported(string $path, CompanyCode $company, Bank $bank, ImportResult $result): self
    {
        return new self($path, $company, $bank, $result, null);
    }

    /** The file was read as a statement and could not be imported; it was moved to errores/. */
    public static function failed(string $path, CompanyCode $company, Bank $bank, string $problem): self
    {
        return new self($path, $company, $bank, null, $problem);
    }

    /** The file is not where a statement belongs (company/bank/file); it was left in place. */
    public static function skipped(string $path, string $problem): self
    {
        return new self($path, null, null, null, $problem);
    }

    public function isImported(): bool
    {
        return $this->result !== null;
    }

    public function isFailed(): bool
    {
        return $this->company !== null && $this->problem !== null;
    }
}
