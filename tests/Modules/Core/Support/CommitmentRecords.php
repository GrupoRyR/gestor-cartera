<?php

namespace Tests\Modules\Core\Support;

use App\Models\User;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\Data\Commitment;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Role;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;

/**
 * The people, clients and commitments of the CompromisoService tests, and
 * readers of what the service saved, always read again from the database.
 *
 * The cast: Carlos Pérez is the SGX advisor 12 and Diana Duque the SGX
 * advisor 20 (role asesor); Hernán Toro is the SDC advisor 12; María Paula
 * Ríos is the portfolio analyst and Miguel Mejía the portfolio manager, both
 * in the two companies.
 */
final class CommitmentRecords
{
    /**
     * The service of the container, as a controller gets it.
     */
    public static function service(): CompromisoService
    {
        return app(CompromisoService::class);
    }

    /**
     * Creates the cast and returns them by first name in lowercase: carlos,
     * diana, hernan, mariaPaula and miguel.
     *
     * @return array{carlos: User, diana: User, hernan: User, mariaPaula: User, miguel: User}
     */
    public static function people(): array
    {
        $carlos = TeamUsers::member('Carlos Pérez', 'carlos.perez@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);
        TeamUsers::advisor(CompanyCode::SGX, 12, 'Carlos Pérez', $carlos);

        $diana = TeamUsers::member('Diana Duque', 'diana.duque@segurex.com', Role::SalesAdvisor, [CompanyCode::SGX]);
        TeamUsers::advisor(CompanyCode::SGX, 20, 'Diana Duque', $diana);

        $hernan = TeamUsers::member('Hernán Toro', 'hernan.toro@derivadoscol.com', Role::SalesAdvisor, [CompanyCode::SDC]);
        TeamUsers::advisor(CompanyCode::SDC, 12, 'Hernán Toro', $hernan);

        return [
            'carlos' => $carlos,
            'diana' => $diana,
            'hernan' => $hernan,
            'mariaPaula' => TeamUsers::member('María Paula Ríos', 'maria.rios@segurex.com', Role::PortfolioAnalyst, [CompanyCode::SGX, CompanyCode::SDC]),
            'miguel' => TeamUsers::member('Miguel Mejía', 'miguel.mejia@segurex.com', Role::PortfolioManager, [CompanyCode::SGX, CompanyCode::SDC]),
        ];
    }

    /**
     * A saved commitment of $client created by $creator, due on $dueDate
     * ("Y-m-d"), pending unless $attributes say otherwise. Its advisor is the
     * client's.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function commitment(
        ClientRecord $client,
        User $creator,
        string $dueDate,
        string $amount = '1000000.00',
        array $attributes = [],
    ): CommitmentRecord {
        return CommitmentRecord::factory()->create([
            'client_id' => $client->id,
            'company' => $client->company,
            'advisor_code' => $client->advisor_code,
            'amount' => $amount,
            'due_date' => $dueDate,
            'created_by' => $creator->getKey(),
            ...$attributes,
        ]);
    }

    /**
     * The commitment as stored now.
     */
    public static function stored(int|Commitment|CommitmentRecord $commitment): CommitmentRecord
    {
        $id = is_int($commitment) ? $commitment : $commitment->id;

        return CommitmentRecord::query()->findOrFail($id);
    }

    /**
     * The status of every given commitment as stored now, in order.
     *
     * @return list<CommitmentStatus>
     */
    public static function statuses(CommitmentRecord ...$commitments): array
    {
        return array_map(fn (CommitmentRecord $commitment): CommitmentStatus => self::stored($commitment)->status, $commitments);
    }

    /**
     * The entries of $client's log, oldest first; only those of $type when
     * given.
     *
     * @return list<ActivityRecord>
     */
    public static function logOf(ClientRecord $client, ?ActivityType $type = null): array
    {
        return ActivityRecord::query()
            ->where('client_id', $client->id)
            ->when($type !== null, fn ($entries) => $entries->where('type', $type))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The ids of a page or list of commitments, in order.
     *
     * @param  iterable<Commitment>  $commitments
     * @return list<int>
     */
    public static function idsOf(iterable $commitments): array
    {
        $ids = [];

        foreach ($commitments as $commitment) {
            $ids[] = $commitment->id;
        }

        return $ids;
    }

    /**
     * The ids of the given records, in order.
     *
     * @return list<int>
     */
    public static function ids(CommitmentRecord ...$commitments): array
    {
        return array_map(fn (CommitmentRecord $commitment): int => $commitment->id, $commitments);
    }
}
