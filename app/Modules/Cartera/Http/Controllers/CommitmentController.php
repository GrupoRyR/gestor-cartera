<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Http\Requests\CancelCommitmentRequest;
use App\Modules\Cartera\Http\Requests\StoreCommitmentRequest;
use App\Modules\Cartera\Http\Requests\UpdateCommitmentRequest;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;

/**
 * Writes to payment commitments (cartera.compromisos.*): the analyst creates,
 * edits, fulfils and cancels those of SDC clients.
 *
 * CompromisoService decides who may write, keeps the client's log and the
 * audit trail, and moves the client's status. A commitment or client out of
 * the user's reach is a 404, a user the service refuses gets a 403, and one
 * that has already closed is told so instead of a failure page.
 */
final class CommitmentController
{
    public function __construct(private readonly CompromisoService $commitments) {}

    /**
     * POST /cartera/clientes/{client}/compromisos
     */
    public function store(StoreCommitmentRequest $request, int $client): RedirectResponse
    {
        $data = $request->validated()['compromiso'];

        try {
            $this->commitments->create(new NewCommitment(
                clientId: $client,
                dueDate: $this->date($data['fecha']),
                amount: (float) $data['valor'],
                notes: $this->text($data['notas'] ?? null),
            ));
        } catch (RecordNotFound) {
            abort(404);
        }

        return redirect()->route('cartera.clientes.show', $client)->with('status', __('cartera::compromisos.mensajes.registrado'));
    }

    /**
     * PUT /cartera/compromisos/{commitment}
     */
    public function update(UpdateCommitmentRequest $request, int $commitment): RedirectResponse
    {
        $data = $request->validated()[$request->form()] ?? [];

        $changes = new CommitmentChanges(
            dueDate: isset($data['fecha']) ? $this->date($data['fecha']) : null,
            amount: isset($data['valor']) ? (float) $data['valor'] : null,
            notes: $this->text($data['notas'] ?? null),
        );

        return $this->change(fn () => $this->commitments->update($commitment, $changes), 'actualizado');
    }

    /**
     * POST /cartera/compromisos/{commitment}/cumplir
     */
    public function fulfil(int $commitment): RedirectResponse
    {
        return $this->change(fn () => $this->commitments->markFulfilled($commitment), 'cumplido');
    }

    /**
     * POST /cartera/compromisos/{commitment}/anular
     */
    public function cancel(CancelCommitmentRequest $request, int $commitment): RedirectResponse
    {
        $reason = (string) $request->validated()[$request->form()]['motivo'];

        return $this->change(fn () => $this->commitments->cancel($commitment, $reason), 'anulado');
    }

    /**
     * Runs a change of an existing commitment and goes back to where the
     * request came from.
     *
     * @param  callable(): mixed  $change
     */
    private function change(callable $change, string $message): RedirectResponse
    {
        try {
            $change();
        } catch (RecordNotFound) {
            abort(404);
        } catch (DomainException) {
            return back()->with('error', __('cartera::compromisos.mensajes.cerrado'));
        }

        return back()->with('status', __('cartera::compromisos.mensajes.'.$message));
    }

    private function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: CarbonImmutable::today();
    }

    private function text(?string $text): ?string
    {
        $text = $text === null ? '' : trim($text);

        return $text === '' ? null : $text;
    }
}
