<?php

namespace App\Modules\Ingesta\Providers;

use App\Modules\Core\Contracts\AvisoService;
use App\Modules\Core\Contracts\Events\BatchAccepted;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Ingesta\Application\Failures\LoadNotices;
use App\Modules\Ingesta\Application\Watch\WatchLoadSlot;
use App\Modules\Ingesta\Console\CheckDailyLoadCommand;
use App\Modules\Ingesta\Console\PruneManualUploadsCommand;
use App\Modules\Ingesta\Console\WatchLoadCommand;
use App\Modules\Ingesta\Infrastructure\DailyCarteraCheck;
use App\Modules\Ingesta\Infrastructure\EloquentIngestaSink;
use App\Support\Modules\ModuleServiceProvider;
use App\Support\Navigation\NavItem;
use App\Support\Navigation\NavRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use UnexpectedValueException;

/**
 * Ingesta: signed endpoint for the ETL robot batches, load history, manual
 * Excel upload, the missing-load alert and the load watch (docs/prompt-gestor-cartera.md,
 * Part B, "Módulo 1 — Ingesta").
 *
 * Web routes: /ingesta/*, named ingesta.*.
 * API routes: /api/ingesta/*, named ingesta.api.*.
 * Owner: the "Ingesta" session (phase 1).
 */
final class IngestaServiceProvider extends ModuleServiceProvider
{
    protected function registerModule(): void
    {
        $this->app->singleton(IngestaSink::class, EloquentIngestaSink::class);
    }

    protected function bootModule(): void
    {
        // The robot sends a handful of batches a day; this only caps a
        // runaway loop or a flood of forged requests: 60 a minute from one
        // IP and 120 from all of them, so many IPs cannot keep the server
        // hashing bodies (audit S-19). The throttle checks both before
        // counting either, so a blocked IP does not use up the global quota.
        // The throttle keys each counter as the limiter name plus by(), so
        // the two by() values ("ip:…" and "global") must never collide.
        RateLimiter::for('ingesta-robot', fn (Request $request): array => [
            Limit::perMinute(60)->by('ip:'.$request->ip()),
            Limit::perMinute(120)->by('global'),
        ]);

        $this->commands([CheckDailyLoadCommand::class, PruneManualUploadsCommand::class, WatchLoadCommand::class]);

        // The bell notice of a load that failed for good: any accepted load
        // of its company and dataset (robot, manual upload) resolves it, and
        // it opens the load history.
        Event::listen(BatchAccepted::class, LoadNotices::class);
        $this->app->make(AvisoService::class)->linkUsing(LoadNotices::KIND, (new LoadNotices)->link(...));
    }

    /**
     * At the deadline (Bogotá), mail the alert when the robot cartera of
     * the day is missing; at 03:00, delete the manual uploads nobody
     * confirmed or discarded; vigilante_min minutes after each slot of
     * etl/horarios.json (on its days, in its timezone), watch that its load
     * arrived. All take seconds, so a lock left by a killed process expires
     * after 10 minutes instead of a day. An invalid etl/horarios.json is
     * logged and only leaves the watch unscheduled, never the other tasks.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(CheckDailyLoadCommand::class)
            ->dailyAt((string) config('ingesta.alert_deadline'))
            ->timezone(DailyCarteraCheck::TIMEZONE)
            ->withoutOverlapping(10);

        $schedule->command(PruneManualUploadsCommand::class)
            ->dailyAt('03:00')
            ->timezone(DailyCarteraCheck::TIMEZONE)
            ->withoutOverlapping(10);

        try {
            $slots = $this->app->make(WatchLoadSlot::class)->slots();
        } catch (UnexpectedValueException $e) {
            Log::error('Ingesta: the load slots are invalid, so the load watch is not scheduled.', ['exception' => $e]);

            return;
        }

        foreach ($slots->slots as $slot) {
            $event = $schedule->command(WatchLoadCommand::class, [$slot->time])
                ->dailyAt($slot->watchAt($slots->watchDelay))
                ->timezone($slots->timezone)
                ->withoutOverlapping(10);

            if ($slot->days !== []) {
                $event->days($slot->dayNumbers());
            }
        }
    }

    /**
     * Cargas de datos sits in Administración, after Core's entries (900
     * and 910): the home page redirects to the first visible entry, so an
     * admin keeps landing on Equipo.
     */
    protected function navigation(NavRegistry $navigation): void
    {
        $navigation->add(new NavItem(
            key: 'ingesta.cargas',
            label: 'Cargas de datos',
            route: 'ingesta.index',
            icon: 'history',
            permission: 'ingesta.view',
            group: 'Administración',
            order: 920,
            activePattern: 'ingesta.*',
        ));
    }
}
