<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Data\Aviso;
use App\Modules\Core\Contracts\Data\Notice;
use Closure;
use PHPUnit\Framework\Assert;

/**
 * In-memory AvisoService: records every Aviso and never writes a notice.
 *
 * resolve() counts like the real service, one per recipient of each open
 * Aviso with that key; link() runs the resolvers registered with
 * linkUsing() as the real service does.
 */
final class FakeAvisoService implements AvisoService
{
    /**
     * @var list<Aviso>
     */
    private array $sent = [];

    /**
     * Whether the Aviso at the same index of $sent is resolved.
     *
     * @var list<bool>
     */
    private array $resolved = [];

    /**
     * @var list<string>
     */
    private array $resolvedKeys = [];

    /**
     * @var array<string, list<Closure(Notice): ?string>>
     */
    private array $resolvers = [];

    public function send(Aviso $aviso): void
    {
        $this->sent[] = $aviso;
        $this->resolved[] = false;
    }

    public function resolve(string $key): int
    {
        $this->resolvedKeys[] = $key;
        $count = 0;

        foreach ($this->sent as $index => $aviso) {
            if ($aviso->key === $key && ! $this->resolved[$index]) {
                $this->resolved[$index] = true;
                $count += count($aviso->recipients);
            }
        }

        return $count;
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
     * @return list<Aviso>
     */
    public function sent(): array
    {
        return $this->sent;
    }

    /**
     * Every key passed to resolve(), in order, repeats included.
     *
     * @return list<string>
     */
    public function resolvedKeys(): array
    {
        return $this->resolvedKeys;
    }

    /**
     * Asserts that an Aviso of this kind was sent, to this user when given.
     */
    public function assertSent(string $kind, ?int $userId = null): void
    {
        $matches = array_filter(
            $this->sent,
            fn (Aviso $aviso): bool => $aviso->kind === $kind
                && ($userId === null || in_array($userId, $aviso->recipients, true)),
        );

        Assert::assertNotEmpty($matches, $userId === null
            ? "No notice of kind {$kind} was sent."
            : "No notice of kind {$kind} was sent to user {$userId}.");
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent, count($this->sent).' notices were sent unexpectedly.');
    }

    /**
     * Asserts that resolve() was called with this key.
     */
    public function assertResolved(string $key): void
    {
        Assert::assertContains($key, $this->resolvedKeys, "The notices with key {$key} were not resolved.");
    }
}
