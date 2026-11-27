<?php

namespace App\Modules\Cartera\Application\Calls;

use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Links a call agreed as a payment commitment to the commitment registered
 * after it. A call that LogCall saved with "Compromiso de pago" leads to
 * the "Nuevo compromiso" form with ?llamada={id}; the form carries the id
 * and, once CompromisoService creates the commitment, the call keeps its id
 * in commitment_id.
 *
 * Only a pending call qualifies: one of the same client, agreed as a payment
 * commitment, and not linked yet. Linking is a conditional update, so saving
 * twice never replaces the first commitment.
 */
final class AttachCommitment
{
    /**
     * The pending call of $clientId that $callId names (an id from the URL or
     * old input); null when it does not name one.
     */
    public function pendingCall(int $clientId, mixed $callId): ?CallRecord
    {
        $id = self::id($callId);

        return $id === null ? null : $this->pending($clientId)->whereKey($id)->first();
    }

    /**
     * Sets the call's commitment_id when it is a pending call of $clientId.
     * Returns whether it linked the call.
     */
    public function attach(int $callId, int $clientId, int $commitmentId): bool
    {
        return $this->pending($clientId)->whereKey($callId)->update(['commitment_id' => $commitmentId]) === 1;
    }

    /**
     * @return Builder<CallRecord>
     */
    private function pending(int $clientId): Builder
    {
        return CallRecord::query()
            ->where('client_id', $clientId)
            ->where('agreement', CallAgreement::PaymentCommitment->value)
            ->whereNull('commitment_id');
    }

    private static function id(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/', $value) === 1 ? (int) $value : null;
    }
}
