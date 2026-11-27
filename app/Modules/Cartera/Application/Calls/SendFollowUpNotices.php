<?php

namespace App\Modules\Cartera\Application\Calls;

use App\Models\User;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\CarteraRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rings the bell of whoever logged a call on the day they agreed to call
 * the client again (FollowUpNotice): every open follow-up due today or
 * earlier (Bogotá calendar) that was not rung yet. A day that already
 * passed, e.g. while the server was down, still rings, once.
 *
 * Each follow-up is claimed first, with a conditional update of
 * follow_up_notified_at, and its notice is sent in the same transaction:
 * a failed send rolls the claim back so the next run retries it, and two
 * runs at once never ring the same follow-up twice.
 *
 * A follow-up is skipped, with an info line in the log and without being
 * claimed, when its caller is no longer an active user with cartera.manage
 * or its client no longer exists (read as the system: the console has no
 * signed-in user). It is checked again on every run, so it rings as soon as
 * the caller is enabled again or the client comes back, and it stops being
 * checked when a new call on the client closes it. Without an AvisoService
 * the run logs an error and claims nothing.
 */
final readonly class SendFollowUpNotices
{
    /**
     * The calendar the follow-up days belong to.
     */
    public const string TIMEZONE = 'America/Bogota';

    /**
     * Candidate calls read per query.
     */
    public const int CHUNK = 100;

    public function __construct(
        private Container $container,
        private CarteraRepository $cartera,
        private ConnectionInterface $database,
    ) {}

    /**
     * Rings the follow-ups due as of $today (now in Bogotá when null).
     *
     * @return array{sent: int, skipped: int, failed: int} How many were rung, skipped, and failed and were reported.
     */
    public function run(?CarbonImmutable $today = null): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $this->container->bound(AvisoService::class)) {
            Log::error('Cartera: AvisoService has no implementation, so no call follow-up was rung.');

            return $result;
        }

        $avisos = $this->container->make(AvisoService::class);
        $clients = $this->cartera->asSystem();
        $today ??= CarbonImmutable::now(self::TIMEZONE);

        // A stored date, with or without the time the date cast writes,
        // sorts before tomorrow's "Y-m-d" exactly when its day is today or earlier.
        CallRecord::query()
            ->whereNotNull('follow_up_on')
            ->where('follow_up_on', '<', $today->addDay()->toDateString())
            ->whereNull('follow_up_notified_at')
            ->whereNull('follow_up_closed_at')
            ->chunkById(self::CHUNK, function (Collection $calls) use ($avisos, $clients, &$result): void {
                foreach ($calls as $call) {
                    try {
                        $outcome = $this->ring($call, $avisos, $clients);
                    } catch (Throwable $exception) {
                        report($exception);
                        $outcome = 'failed';
                    }

                    if ($outcome !== null) {
                        $result[$outcome]++;
                    }
                }
            });

        return $result;
    }

    /**
     * Rings one follow-up: "sent", "skipped", or null when another run
     * claimed it or a new call closed it since it was read.
     *
     * @return 'sent'|'skipped'|null
     */
    private function ring(CallRecord $call, AvisoService $avisos, CarteraRepository $clients): ?string
    {
        $caller = User::query()->where('is_active', true)->find($call->user_id);

        if (! $caller instanceof User || ! $caller->can('cartera.manage')) {
            Log::info('Cartera: a call follow-up was not rung because its caller can no longer log calls.', [
                'call_id' => $call->id,
                'user_id' => $call->user_id,
            ]);

            return 'skipped';
        }

        $client = $clients->client($call->client_id);

        if ($client === null) {
            Log::info('Cartera: a call follow-up was not rung because its client no longer exists.', [
                'call_id' => $call->id,
                'client_id' => $call->client_id,
            ]);

            return 'skipped';
        }

        return $this->database->transaction(function () use ($call, $avisos, $client): ?string {
            $claimed = CallRecord::query()
                ->whereKey($call->id)
                ->whereNull('follow_up_notified_at')
                ->whereNull('follow_up_closed_at')
                ->update(['follow_up_notified_at' => CarbonImmutable::now()]);

            if ($claimed !== 1) {
                return null;
            }

            $avisos->send(FollowUpNotice::for($call, $client->summary->name));

            return 'sent';
        });
    }
}
