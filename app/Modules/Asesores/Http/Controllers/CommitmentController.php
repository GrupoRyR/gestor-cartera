<?php

namespace App\Modules\Asesores\Http\Controllers;

use App\Modules\Asesores\Application\Commitments\CommitmentActions;
use App\Modules\Asesores\Application\Commitments\CommitmentRefused;
use App\Modules\Asesores\Http\Requests\CancelCommitmentRequest;
use App\Modules\Asesores\Http\Requests\FulfillCommitmentRequest;
use App\Modules\Asesores\Http\Requests\SaveCommitmentRequest;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use App\Support\Formatting\DateFormat;
use App\Support\Formatting\Money;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Payment commitments. Every id is found through Core first, which hides what
 * is outside the user's scope: a client or commitment of another advisor is a
 * 404, the same answer as for an id nobody has. Only then does the policy
 * decide whether the user writes, and CompromisoService has the last word.
 */
final readonly class CommitmentController
{
    public function __construct(
        private CompromisoService $commitments,
        private CarteraRepository $cartera,
        private CommitmentActions $actions,
    ) {}

    /**
     * GET /asesores/compromisos/{commitment}: one commitment, with the forms
     * to change it for who may. Sales managers read it without them.
     */
    public function show(int $commitment): View
    {
        $found = $this->commitments->find($commitment) ?? abort(404);

        return view('asesores::compromisos.show', [
            'commitment' => $found,
            // The commitment may belong to a company other than the active one.
            'client' => $this->cartera->client($found->clientId),
            'today' => CarbonImmutable::today(),
        ]);
    }

    /**
     * POST /asesores/clientes/{client}/compromisos.
     */
    public function store(SaveCommitmentRequest $request, int $client): RedirectResponse
    {
        $detail = $this->cartera->client($client) ?? abort(404);

        Gate::authorize('manage', $detail);

        return $this->perform(
            fn (): Commitment => $this->actions->create($client, $request->dueDate(), $request->amount(), $request->notes()),
            fn (Commitment $created): string => __('asesores::compromisos.mensajes.creado', [
                'valor' => Money::format($created->amount),
                'fecha' => DateFormat::date($created->dueDate),
            ]),
        );
    }

    /**
     * PUT /asesores/compromisos/{commitment}.
     */
    public function update(SaveCommitmentRequest $request, int $commitment): RedirectResponse
    {
        $this->authorized($commitment);

        return $this->perform(
            fn (): Commitment => $this->actions->update($commitment, $request->dueDate(), $request->amount(), $request->notes()),
            fn (): string => __('asesores::compromisos.mensajes.actualizado'),
        );
    }

    /**
     * POST /asesores/compromisos/{commitment}/cumplir.
     */
    public function fulfill(FulfillCommitmentRequest $request, int $commitment): RedirectResponse
    {
        $this->authorized($commitment);

        return $this->perform(
            fn (): Commitment => $this->actions->fulfill($commitment, $request->note()),
            fn (): string => __('asesores::compromisos.mensajes.cumplido'),
        );
    }

    /**
     * POST /asesores/compromisos/{commitment}/anular.
     */
    public function cancel(CancelCommitmentRequest $request, int $commitment): RedirectResponse
    {
        $this->authorized($commitment);

        return $this->perform(
            fn (): Commitment => $this->actions->cancel($commitment, $request->reason()),
            fn (): string => __('asesores::compromisos.mensajes.anulado'),
        );
    }

    /**
     * Finds the commitment inside the user's scope (404 otherwise) and checks
     * that they write commitments (403 otherwise).
     */
    private function authorized(int $id): Commitment
    {
        $commitment = $this->commitments->find($id) ?? abort(404);

        Gate::authorize('manage', $commitment);

        return $commitment;
    }

    /**
     * Runs the write and goes back to the client file with the message; when
     * Core refuses, goes back to the form with the reason.
     *
     * @param  Closure(): Commitment  $action
     * @param  Closure(Commitment): string  $message
     */
    private function perform(Closure $action, Closure $message): RedirectResponse
    {
        try {
            $commitment = $action();
        } catch (RecordNotFound) {
            abort(404);
        } catch (CommitmentRefused $refused) {
            $back = back()->withInput();

            return $refused->field === null
                ? $back->with('error', $refused->getMessage())
                : $back->withErrors([$refused->field => $refused->getMessage()]);
        }

        return redirect()
            ->route('asesores.clientes.show', $commitment->clientId)
            ->with('status', $message($commitment));
    }
}
