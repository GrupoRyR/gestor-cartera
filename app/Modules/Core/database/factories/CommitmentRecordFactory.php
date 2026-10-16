<?php

namespace App\Modules\Core\Database\Factories;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CommitmentStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\CommitmentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious payment commitments: a pending promise of a round amount due in
 * a week, in the company and with the advisor of its client. Each one gets
 * its own client and author unless the test passes client_id and
 * created_by.
 *
 * @extends Factory<CommitmentRecord>
 */
final class CommitmentRecordFactory extends Factory
{
    /**
     * @var class-string<CommitmentRecord>
     */
    protected $model = CommitmentRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Before company and advisor_code: their closures read the created client.
            'client_id' => ClientRecord::factory(),
            'company' => fn (array $attributes): CompanyCode => self::client($attributes)->company,
            'advisor_code' => fn (array $attributes): ?int => self::client($attributes)->advisor_code,
            'amount' => sprintf('%d.00', fake()->numberBetween(1, 50) * 100_000),
            'due_date' => now()->addWeek()->toDateString(),
            'status' => CommitmentStatus::Pending,
            'notes' => null,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that the client paid as promised.
     */
    public function fulfilled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommitmentStatus::Fulfilled,
            'fulfilled_at' => now(),
        ]);
    }

    /**
     * Indicate that the due date passed without payment.
     */
    public function broken(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommitmentStatus::Broken,
            'broken_at' => now(),
        ]);
    }

    /**
     * Indicate that a user voided it.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CommitmentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Registrado por error.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function client(array $attributes): ClientRecord
    {
        return ClientRecord::withTrashed()->findOrFail($attributes['client_id']);
    }
}
