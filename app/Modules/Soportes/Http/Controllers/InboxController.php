<?php

namespace App\Modules\Soportes\Http\Controllers;

use App\Models\User;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Soportes\Application\Inbox\InboxFilter;
use App\Modules\Soportes\Application\Inbox\VisibleReceipts;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /soportes (soportes.index): the receipts inbox, one tab per status
 * with its count. Reviewers see the receipts of the companies in view and,
 * like the superadmin (who reaches every client), get the general title;
 * the advisor sees "Mis soportes" (VisibleReceipts). Pending receipts come
 * oldest first, as a queue; applied and rejected ones, latest review first.
 */
final readonly class InboxController
{
    private const int PER_PAGE = 25;

    public function __construct(private VisibleReceipts $visible) {}

    public function index(Request $request, CurrentCompany $current): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $filter = InboxFilter::fromInput((array) $request->query());
        $visible = $filter->narrow($this->visible->for($user));

        $counts = [];
        $totals = (clone $visible)->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        foreach (ReceiptStatus::cases() as $status) {
            $counts[$status->value] = (int) ($totals[$status->value] ?? 0);
        }

        $receipts = $visible
            ->where('status', $filter->status->value)
            ->with('uploader:id,name')
            ->withCount('invoices');

        $receipts = $filter->status === ReceiptStatus::Pending
            ? $receipts->oldest()->orderBy('id')
            : $receipts->latest('reviewed_at')->orderByDesc('id');

        return view('soportes::bandeja.index', [
            'filter' => $filter,
            'receipts' => $receipts->paginate(self::PER_PAGE),
            'counts' => $counts,
            'isGroup' => $current->isGroup(),
            'general' => $user->can('soportes.review') || $user->hasRole(Role::SuperAdmin->value),
            'canUpload' => $user->can('create', Receipt::class),
        ]);
    }
}
