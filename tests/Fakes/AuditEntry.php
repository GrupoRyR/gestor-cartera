<?php

namespace Tests\Fakes;

/**
 * One call to AuditLogger::record() captured by FakeAuditLogger.
 */
final readonly class AuditEntry
{
    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $action,
        public ?string $subjectType,
        public int|string|null $subjectId,
        public array $changes,
        public array $context,
    ) {}
}
