<?php

namespace App\Modules\Core\Contracts\Data;

use Carbon\CarbonImmutable;

/**
 * A stored notice of one user, as the link resolvers and the bell read it.
 * The fields copy the Aviso it came from.
 */
final readonly class Notice
{
    /**
     * @param  int  $userId  The recipient.
     * @param  array<string, mixed>  $data  Aviso::$data; empty when it had none.
     * @param  CarbonImmutable|null  $readAt  When the recipient read it; null = unread.
     * @param  CarbonImmutable|null  $resolvedAt  When AvisoService::resolve() closed it; null = open.
     */
    public function __construct(
        public int $id,
        public int $userId,
        public ?string $company,
        public string $kind,
        public string $title,
        public ?string $body,
        public ?string $subjectType,
        public ?int $subjectId,
        public ?string $key,
        public array $data,
        public ?CarbonImmutable $readAt,
        public ?CarbonImmutable $resolvedAt,
        public CarbonImmutable $createdAt,
    ) {}

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /**
     * Whether it is done; independent of isRead().
     */
    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }
}
