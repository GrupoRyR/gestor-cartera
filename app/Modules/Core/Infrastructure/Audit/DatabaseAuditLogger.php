<?php

namespace App\Modules\Core\Infrastructure\Audit;

use App\Models\User;
use App\Modules\Core\Contracts\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * AuditLogger that appends each entry to "audit_logs" (AuditLogRecord).
 *
 * The actor is the user signed in on the default guard, or the system (no
 * user) in jobs and console commands. The IP address and the user agent come
 * from the HTTP request being handled, if any. Values in $changes and
 * $context must be JSON-encodable; keys that look like secrets are redacted
 * as a safety net, although the contract already forbids secrets.
 *
 * Every entry is chained to the one before it through AuditChain (audit
 * S-14), inside the caller's transaction when there is one: if the caller
 * rolls back, the entry and the move of the chain head roll back with it.
 */
final class DatabaseAuditLogger implements AuditLogger
{
    /**
     * "<module-key>.<entity>.<verb>": three or more lowercase snake_case segments.
     */
    private const string ACTION = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/';

    /**
     * Keys whose values are never stored, at any depth.
     */
    private const string SECRET_KEY = '/password|secret|token/i';

    private const string REDACTED = '[redacted]';

    private const int USER_AGENT_LENGTH = 255;

    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Application $app,
        private readonly AuditChain $chain,
    ) {}

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

        $user = $this->auth->guard()->user();
        $request = $this->request();
        $userAgent = $request?->userAgent();

        $this->chain->append([
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'changes' => $changes === [] ? null : $this->redact($changes),
            'context' => $context === [] ? null : $this->redact($context),
            'user_id' => $user?->getAuthIdentifier(),
            'actor' => $user instanceof User ? $user->email : null,
            'ip_address' => $request?->ip(),
            // Scrubbed: invalid UTF-8 in a header must not make MySQL reject the entry.
            'user_agent' => $userAgent === null ? null : mb_substr(mb_scrub($userAgent, 'UTF-8'), 0, self::USER_AGENT_LENGTH),
            // Set here, not by Eloquent, because the hash covers it.
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * The HTTP request being handled. Console commands and queue workers
     * also have a "request" (made up by SetRequestForConsole), but it never
     * goes through the router; HTTP tests run in the console too, so a routed
     * request is what tells them apart there.
     */
    private function request(): ?Request
    {
        $request = $this->app->bound('request') ? $this->app->make('request') : null;

        if (! $request instanceof Request) {
            return null;
        }

        return ! $this->app->runningInConsole() || $request->route() !== null ? $request : null;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
