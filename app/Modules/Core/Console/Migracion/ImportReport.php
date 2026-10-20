<?php

namespace App\Modules\Core\Console\Migracion;

/**
 * What one migration run did, per file: rows read, imported (something was
 * saved), skipped (already in the app or not applicable) and rejected; plus
 * the reason of each rejected row and the warnings about rows that were
 * imported or skipped only in part. MigrationCommand prints it at the end.
 */
final class ImportReport
{
    /**
     * @var array<string, array{imported: int, skipped: int, rejected: int}>
     */
    private array $totals = [];

    /**
     * @var list<array{file: string, line: int, id: string, reason: string}>
     */
    private array $rejections = [];

    /**
     * @var list<array{file: string, line: int, id: string, reason: string}>
     */
    private array $warnings = [];

    /**
     * Lists $file in the report, even if it turns out to have no rows.
     */
    public function opened(CsvFile $file): void
    {
        $this->totals[$file->name] ??= ['imported' => 0, 'skipped' => 0, 'rejected' => 0];
    }

    public function imported(CsvRow $row): void
    {
        $this->count($row, 'imported');
    }

    public function skipped(CsvRow $row): void
    {
        $this->count($row, 'skipped');
    }

    public function rejected(CsvRow $row, string $reason): void
    {
        $this->count($row, 'rejected');
        $this->rejections[] = $this->entry($row, $reason);
    }

    /**
     * Notes something about $row that did not stop it (e.g. an advisor code
     * that does not exist); the row is still counted as imported or skipped.
     */
    public function warn(CsvRow $row, string $reason): void
    {
        $this->warnings[] = $this->entry($row, $reason);
    }

    /**
     * The counts of each file, in the order the files were opened.
     *
     * @return array<string, array{read: int, imported: int, skipped: int, rejected: int}>
     */
    public function totals(): array
    {
        return array_map(
            fn (array $counts): array => ['read' => array_sum($counts), ...$counts],
            $this->totals,
        );
    }

    /**
     * @return list<array{file: string, line: int, id: string, reason: string}>
     */
    public function rejections(): array
    {
        return $this->rejections;
    }

    /**
     * @return list<array{file: string, line: int, id: string, reason: string}>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @param  'imported'|'skipped'|'rejected'  $outcome
     */
    private function count(CsvRow $row, string $outcome): void
    {
        $this->totals[$row->file] ??= ['imported' => 0, 'skipped' => 0, 'rejected' => 0];
        $this->totals[$row->file][$outcome]++;
    }

    /**
     * @return array{file: string, line: int, id: string, reason: string}
     */
    private function entry(CsvRow $row, string $reason): array
    {
        return ['file' => $row->file, 'line' => $row->line, 'id' => $row->legacyId(), 'reason' => $reason];
    }
}
