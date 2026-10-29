<?php

namespace App\Modules\Comisiones\Infrastructure\Audit;

use App\Models\User;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use App\Modules\Core\Contracts\AuditLogger;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use InvalidArgumentException;

/**
 * The module's immutable audit trail (§10, §7.9): every event is a row of
 * comisiones_bitacora, which the audit screen filters by user, date and
 * event, and an entry of Core's platform-wide AuditLogger.
 *
 * Events are named "comisiones.<entity>.<verb>" in lowercase snake_case
 * segments, e.g. comisiones.load.imported or comisiones.parameter.version_added.
 */
final class AuditTrail
{
    /** Core's naming rule for audit actions. */
    private const string EVENT = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){2,}$/';

    /**
     * Keys whose value is never stored, at any depth and in any case: the
     * same rule as Core's DatabaseAuditLogger (audit S-29).
     */
    private const string SECRET_KEY = '/password|secret|token/i';

    private const string REDACTED = '[redacted]';

    /** Shown as the user of the events without one (loads, scheduled tasks). */
    public const string SYSTEM = 'Sistema';

    public function __construct(
        private readonly AuditLogger $logger,
    ) {}

    /**
     * Records one event.
     *
     * @param  array<string, mixed>  $changes  field => ['old' => …, 'new' => …], or field => value.
     * @param  array<string, mixed>  $context  Anything else worth keeping, e.g. the load or the version.
     * @param  string|null  $period  "YYYY-MM" when the event belongs to a period.
     * @param  User|null  $user  Who did it; null takes the authenticated user, or the system.
     *
     * @throws InvalidArgumentException when the event name does not follow Core's rule.
     */
    public function record(
        string $event,
        ?string $subjectType = null,
        int|string|null $subjectId = null,
        array $changes = [],
        array $context = [],
        ?string $period = null,
        ?User $user = null,
        ?string $reason = null,
    ): AuditRecord {
        if (preg_match(self::EVENT, $event) !== 1) {
            throw new InvalidArgumentException("Audit event [{$event}] must look like comisiones.<entity>.<verb>.");
        }

        $user ??= Auth::user() instanceof User ? Auth::user() : null;
        $changes = self::redact($changes);
        $context = self::redact($context);

        $record = AuditRecord::query()->create([
            'evento' => $event,
            'periodo' => $period,
            'user_id' => $user?->id,
            'usuario' => $user->name ?? self::SYSTEM,
            'sujeto_tipo' => $subjectType,
            'sujeto_id' => $subjectId === null ? null : (string) $subjectId,
            'cambios' => $changes,
            'contexto' => $context,
            'motivo' => $reason,
            'ip' => App::runningInConsole() ? null : Request::ip(),
        ]);

        $this->logger->record(
            $event,
            $subjectType,
            $subjectId,
            $changes,
            array_filter([...$context, 'periodo' => $period, 'motivo' => $reason], fn (mixed $value): bool => $value !== null),
        );

        return $record;
    }

    /**
     * @template T of array<array-key, mixed>
     *
     * @param  T  $values
     * @return T
     */
    private static function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $values[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
