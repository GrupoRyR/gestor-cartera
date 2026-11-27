<?php

namespace App\Modules\Cartera\Http\Controllers;

use App\Modules\Cartera\Application\Calls\AttachCommitment;
use App\Modules\Cartera\Http\Requests\CancelCommitmentRequest;
use App\Modules\Cartera\Http\Requests\PartialPaymentRequest;
use App\Modules\Cartera\Http\Requests\StoreCommitmentRequest;
use App\Modules\Cartera\Http\Requests\UpdateCommitmentRequest;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\CommitmentChanges;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Exceptions\RecordNotFound;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Writes to payment commitments (cartera.compromisos.*): the analyst creates,
 * edits and cancels those of SDC clients; the analyst and the manager mark
 * any commitment fulfilled, partially paid or broken, in both companies.
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
     * POST /cartera/clientes/{client}/compromisos. With compromiso[llamada],
     * the commitment agreed on that call: the call is linked once the
     * commitment exists (linkCall()).
     */
    public function store(StoreCommitmentRequest $request, int $client, AttachCommitment $calls): RedirectResponse
    {
        $data = $request->validated()['compromiso'];

        try {
            $commitment = $this->commitments->create(new NewCommitment(
                clientId: $client,
                dueDate: $this->date($data['fecha']),
                amount: (float) $data['valor'],
                notes: $this->text($data['notas'] ?? null),
            ));
        } catch (RecordNotFound) {
            abort(404);
        }

        if (isset($data['llamada'])) {
            $this->linkCall($calls, (int) $data['llamada'], $client, $commitment->id);
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
     * POST /cartera/compromisos/{commitment}/abonar: the client paid only
     * part of it. A paid amount Core refuses (not below the commitment
     * value) goes back to the form as an error on its value.
     */
    public function partiallyPay(PartialPaymentRequest $request, int $commitment): RedirectResponse
    {
        $form = $request->form();
        $data = $request->validated()[$form];

        return $this->change(function () use ($commitment, $data, $form): void {
            try {
                $this->commitments->markPartiallyPaid($commitment, (float) $data['valor'], $this->text($data['nota'] ?? null));
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages([
                    "{$form}.valor" => __('cartera::compromisos.abonar.invalido'),
                ]);
            }
        }, 'abonado');
    }

    /**
     * POST /cartera/compromisos/{commitment}/incumplir
     */
    public function breakOff(int $commitment): RedirectResponse
    {
        return $this->change(fn () => $this->commitments->markBroken($commitment), 'incumplido', 'no_pendiente');
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
     * request came from. $refusal names the message for a commitment whose
     * status does not allow the change.
     *
     * @param  callable(): mixed  $change
     */
    private function change(callable $change, string $message, string $refusal = 'cerrado'): RedirectResponse
    {
        try {
            $change();
        } catch (RecordNotFound) {
            abort(404);
        } catch (DomainException) {
            return back()->with('error', __('cartera::compromisos.mensajes.'.$refusal));
        }

        return back()->with('status', __('cartera::compromisos.mensajes.'.$message));
    }

    /**
     * Links the call to the commitment just created. The commitment already
     * exists, so a call that does not qualify or a failing link only leaves
     * a warning in the log: it never undoes or blocks the commitment.
     */
    private function linkCall(AttachCommitment $calls, int $call, int $client, int $commitment): void
    {
        $context = ['call_id' => $call, 'client_id' => $client, 'commitment_id' => $commitment];

        try {
            if (! $calls->attach($call, $client, $commitment)) {
                Log::warning('Cartera: the call is not a pending payment agreement of the client, so the commitment was not linked to it.', $context);
            }
        } catch (Throwable $e) {
            Log::warning('Cartera: the commitment could not be linked to its call.', $context + ['error' => $e->getMessage()]);
        }
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
