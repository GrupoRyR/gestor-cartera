<?php

namespace App\Modules\Core\Infrastructure\Notices;

use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use InvalidArgumentException;

/**
 * AvisoService over the "notices" table, plus the inbox the bell reads.
 *
 * Bound as a singleton: the link resolvers modules register in bootModule()
 * must outlive the request and every job of a queue worker. It keeps no
 * user state, so sharing it is safe.
 *
 * The inbox methods (forUser(), latest(), unreadCount(), markRead(),
 * markAllRead() and link()) are Core-internal: they take the user id
 * explicitly and never touch another user's notices.
 */
final class EloquentAvisoService implements AvisoService
{
    /**
     * Link resolvers by notice kind, in registration order.
     *
     * @var array<string, list<Closure(Notice): ?string>>
     */
    private array $resolvers = [];

    /**
     * A savepoint inside the caller's transaction, or a transaction of its
     * own: the recipients get their notice together or not at all. The
     * connection is read on each call, never kept by the singleton.
     */
    public function send(Aviso $aviso): void
    {
        NoticeRecord::query()->getConnection()->transaction(function () use ($aviso): void {
            foreach ($aviso->recipients as $userId) {
                NoticeRecord::query()->create([
                    'user_id' => $userId,
                    'company' => $aviso->company,
                    'kind' => $aviso->kind,
                    'title' => $aviso->title,
                    'body' => $aviso->body,
                    'subject_type' => $aviso->subjectType,
                    'subject_id' => $aviso->subjectId,
                    'key' => $aviso->key,
                    'data' => $aviso->data === [] ? null : $aviso->data,
                ]);
            }
        });
    }

    public function resolve(string $key): int
    {
        return NoticeRecord::query()
            ->where('key', $key)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => CarbonImmutable::now()]);
    }

    public function linkUsing(string $kind, Closure $resolver): void
    {
        $this->resolvers[$kind][] = $resolver;
    }

    /**
     * The URL of the first resolver of the notice's kind that answers, or null.
     */
    public function link(Notice $notice): ?string
    {
        foreach ($this->resolvers[$notice->kind] ?? [] as $resolver) {
            $url = $resolver($notice);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * One page of the user's notices (?page=), newest first; with
     * $unreadOnly, only those not read yet (open or resolved).
     *
     * @return LengthAwarePaginator<int, Notice>
     *
     * @throws InvalidArgumentException When $perPage is below 1.
     */
    public function forUser(int $userId, bool $unreadOnly = false, int $perPage = 20): LengthAwarePaginator
    {
        if ($perPage < 1) {
            throw new InvalidArgumentException("The perPage must be at least 1, {$perPage} given.");
        }

        $page = $this->newestOf($userId)
            ->when($unreadOnly, fn (Builder $query) => $query->whereNull('read_at'))
            ->paginate($perPage);

        return new Paginator(
            array_map($this->toNotice(...), $page->items()),
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            $page->getOptions(),
        );
    }

    /**
     * The user's newest notices, read or not.
     *
     * @return list<Notice>
     *
     * @throws InvalidArgumentException When $limit is below 1.
     */
    public function latest(int $userId, int $limit = 10): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, {$limit} given.");
        }

        return array_values($this->newestOf($userId)->limit($limit)->get()->map($this->toNotice(...))->all());
    }

    /**
     * How many of the user's notices are unread and still open: the bell's badge.
     */
    public function unreadCount(int $userId): int
    {
        return NoticeRecord::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->whereNull('resolved_at')
            ->count();
    }

    /**
     * Marks the notice as read and returns it; a notice already read keeps
     * its first read time. Null when it does not exist or is not the user's.
     */
    public function markRead(int $userId, int $noticeId): ?Notice
    {
        $record = NoticeRecord::query()->where('user_id', $userId)->find($noticeId);

        if ($record === null) {
            return null;
        }

        if ($record->read_at === null) {
            $record->read_at = CarbonImmutable::now();
            $record->save();
        }

        return $this->toNotice($record);
    }

    /**
     * Marks every unread notice of the user as read; returns how many.
     */
    public function markAllRead(int $userId): int
    {
        return NoticeRecord::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => CarbonImmutable::now()]);
    }

    /**
     * @return Builder<NoticeRecord>
     */
    private function newestOf(int $userId): Builder
    {
        return NoticeRecord::query()
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function toNotice(NoticeRecord $record): Notice
    {
        return new Notice(
            id: $record->id,
            userId: $record->user_id,
            company: $record->company,
            kind: $record->kind,
            title: $record->title,
            body: $record->body,
            subjectType: $record->subject_type,
            subjectId: $record->subject_id,
            key: $record->key,
            data: $record->data ?? [],
            readAt: $record->read_at,
            resolvedAt: $record->resolved_at,
            // Always set by the timestamps of the model.
            createdAt: $record->created_at ?? CarbonImmutable::now(),
        );
    }
}
