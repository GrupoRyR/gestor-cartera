<?php

namespace App\Modules\Comisiones\Http\Controllers;

use App\Models\User;
use App\Modules\Comisiones\Http\Support\AuditFilters;
use App\Modules\Comisiones\Infrastructure\Audit\AuditLabels;
use App\Modules\Comisiones\Infrastructure\Audit\AuditTrail;
use App\Modules\Comisiones\Infrastructure\Persistence\AuditRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /comisiones/auditoria (comisiones.auditoria.index, comisiones.audit):
 * the module's audit trail (§7.9, §10), most recent first, 50 per page,
 * filtered by user, dates, event and period (AuditFilters). Read only: the
 * trail is insert-only and nothing here changes it.
 */
final class AuditController
{
    private const int PER_PAGE = 50;

    public function __invoke(Request $request): View
    {
        $filters = AuditFilters::fromRequest($request);

        $entries = $filters->apply(AuditRecord::query())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('comisiones::auditoria.index', [
            'entries' => $entries,
            'filters' => $filters,
            'users' => $this->users(),
            'events' => $this->events(),
            'periods' => AuditRecord::query()->whereNotNull('periodo')->distinct()->orderByDesc('periodo')->pluck('periodo')
                ->mapWithKeys(fn (string $period): array => [$period => $period])->all(),
        ]);
    }

    /**
     * The users that appear in the trail, by name, plus «Sistema» when
     * there are events without one.
     *
     * @return array<string, string> Filter value => label.
     */
    private function users(): array
    {
        $ids = AuditRecord::query()->whereNotNull('user_id')->distinct()->pluck('user_id')->all();
        $options = User::withTrashed()->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn (string $name, int $id): array => [(string) $id => $name])
            ->all();

        if (AuditRecord::query()->whereNull('user_id')->exists()) {
            $options[AuditFilters::SYSTEM] = AuditTrail::SYSTEM;
        }

        return $options;
    }

    /**
     * The events that appear in the trail, by label.
     *
     * @return array<string, string> Event name => label.
     */
    private function events(): array
    {
        $events = AuditRecord::query()->distinct()->pluck('evento')
            ->mapWithKeys(fn (string $event): array => [$event => AuditLabels::event($event)])
            ->all();

        asort($events, SORT_LOCALE_STRING);

        return $events;
    }
}
