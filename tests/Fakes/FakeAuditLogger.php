<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\AuditLogger;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;

/**
 * In-memory AuditLogger that keeps every entry and checks the action name.
 */
final class FakeAuditLogger implements AuditLogger
{
    /**
     * "<module-key>.<entity>.<verb>": three or more lowercase snake_case segments.
     */
    private const string ACTION = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/';

    /**
     * @var list<AuditEntry>
     */
    private array $entries = [];

    public function record(
        string $action,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        array $changes = [],
        array $context = [],
    ): void {
        if (preg_match(self::ACTION, $action) !== 1) {
            throw new InvalidArgumentException("Audit action [{$action}] must look like <module-key>.<entity>.<verb>, e.g. cobro.exclusion.created.");
        }

        $this->entries[] = new AuditEntry($action, $subjectType, $subjectId, $changes, $context);
    }

    /**
     * @return list<AuditEntry>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @param  (callable(AuditEntry): bool)|null  $filter
     */
    public function assertRecorded(string $action, ?callable $filter = null): void
    {
        $matching = array_filter(
            $this->entries,
            fn (AuditEntry $entry): bool => $entry->action === $action && ($filter === null || $filter($entry)),
        );

        Assert::assertNotEmpty($matching, "No matching [{$action}] audit entry was recorded.");
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertSame([], $this->entries, count($this->entries).' audit entries were recorded unexpectedly.');
    }
}
