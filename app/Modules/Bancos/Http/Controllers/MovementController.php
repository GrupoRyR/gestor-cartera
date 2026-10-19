<?php

namespace App\Modules\Bancos\Http\Controllers;

use App\Modules\Bancos\Application\Access\VisibleCompanies;
use App\Modules\Bancos\Application\Movements\MovementSearch;
use App\Modules\Bancos\Application\Movements\UpdateMovementStatus;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Http\Requests\ApplyMovementRequest;
use App\Modules\Bancos\Http\Requests\MovementFilterRequest;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The credits of each bank (one tab per bank), with filters, and the screen
 * where the analyst registers the cash receipt number once the payment is
 * applied in SAP.
 */
final class MovementController
{
    private const int PER_PAGE = 25;

    /** Where "back to the list" may point: only the lists of a bank, never another address. */
    private const string LIST_PATH = '#^/bancos/movimientos/[a-z]+$#';

    public function __construct(
        private readonly VisibleCompanies $companies,
        private readonly MovementSearch $search,
        private readonly UpdateMovementStatus $status,
    ) {}

    public function index(MovementFilterRequest $request, string $bank): View
    {
        $bank = Bank::from($bank);
        $filter = $request->filter();
        $visible = $this->companies->inView();

        $matching = $this->search->matching($bank, $visible, $filter);

        return view('bancos::movimientos.index', [
            'bank' => $bank,
            'filter' => $filter,
            'movements' => $this->search->list($bank, $visible, $filter)->paginate(self::PER_PAGE)->withQueryString(),
            'totals' => $this->search->totals($matching),
            'pending' => $this->search->pendingByBank($visible),
            'showCompany' => count($visible) > 1,
        ]);
    }

    public function show(Request $request, BankMovement $movement): View
    {
        $this->authorizeCompany($movement);

        return view('bancos::movimientos.show', [
            'movement' => $movement->loadMissing(['bankLoad', 'appliedBy']),
            'statuses' => MovementStatus::options(),
            'back' => $this->listUrl((string) url()->previous(), $movement),
        ]);
    }

    public function update(ApplyMovementRequest $request, BankMovement $movement): RedirectResponse
    {
        $this->authorizeCompany($movement);

        $userId = $request->user()?->getAuthIdentifier();

        $this->status->handle($movement, $request->status(), $request->receipt(), is_numeric($userId) ? (int) $userId : null);

        return redirect($this->listUrl($request->string('volver')->toString(), $movement))
            ->with('status', __('bancos::movimientos.guardado.'.$request->status()->value));
    }

    /**
     * A movement of a company the user cannot choose does not exist for them.
     */
    private function authorizeCompany(BankMovement $movement): void
    {
        abort_unless($this->companies->canSee($movement->company), 404);
    }

    /**
     * The list the user came from (with its filters) when $candidate is one;
     * the list of the movement's bank otherwise.
     */
    private function listUrl(string $candidate, BankMovement $movement): string
    {
        $parts = parse_url($candidate);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if ((is_string($host) && $host !== request()->getHost()) || preg_match(self::LIST_PATH, $path) !== 1) {
            return route('bancos.movimientos.banco', $movement->bank->value);
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
