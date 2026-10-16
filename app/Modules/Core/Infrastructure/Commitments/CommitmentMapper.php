<?php

namespace App\Modules\Core\Infrastructure\Commitments;

use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Data\UserRef;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Carbon\CarbonImmutable;

/**
 * Turns commitment records into the Commitment of the contract. The client
 * and the author must be loaded with RELATIONS (one query each for a whole
 * page: strict models forbid lazy loading). The client's name is the one it
 * has now; soft-deleted authors still show.
 */
final class CommitmentMapper
{
    /**
     * The relations map() reads, with only the columns it needs.
     *
     * @var list<string>
     */
    public const array RELATIONS = ['client:id,name', 'creator:id,name,email'];

    public function map(CommitmentRecord $record): Commitment
    {
        $creator = $record->creator;

        return new Commitment(
            id: $record->id,
            company: $record->company,
            clientId: $record->client_id,
            clientName: $record->client->name,
            advisorCode: $record->advisor_code,
            amount: (float) $record->amount,
            dueDate: $record->due_date,
            status: $record->status,
            notes: $record->notes,
            createdBy: new UserRef($creator->id, $creator->name, $creator->email),
            // Always set by the timestamps of the model.
            createdAt: $record->created_at ?? CarbonImmutable::now(),
            fulfilledAt: $record->fulfilled_at,
        );
    }
}
