<?php

namespace App\Modules\Soportes\Database\Factories;

use App\Models\User;
use App\Modules\Core\Contracts\Data\ClientSummary;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Soportes\Domain\PaymentMethod;
use App\Modules\Soportes\Domain\ReceiptStatus;
use App\Modules\Soportes\Infrastructure\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious pending receipts of a Segurex client, paid yesterday by
 * transfer, uploaded by a new user. No invoices or files: add them through
 * the relations when a test needs them.
 *
 * @extends Factory<Receipt>
 */
final class ReceiptFactory extends Factory
{
    /**
     * @var class-string<Receipt>
     */
    protected $model = Receipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $clientId = fake()->unique()->numberBetween(1, 999_999);

        return [
            'company' => CompanyCode::SGX,
            'client_id' => $clientId,
            'client_code' => 'C'.(900_000_000 + $clientId),
            'client_name' => fake()->company().' SAS',
            'advisor_code' => null,
            'amount' => fake()->numberBetween(50_000, 15_000_000).'.00',
            'paid_at' => CarbonImmutable::yesterday('America/Bogota'),
            'method' => PaymentMethod::Transfer,
            'note' => null,
            'status' => ReceiptStatus::Pending,
            'uploaded_by' => User::factory(),
        ];
    }

    /**
     * Copies of $client, as the upload takes them.
     */
    public function forClient(ClientSummary $client): static
    {
        return $this->state([
            'company' => $client->company,
            'client_id' => $client->id,
            'client_code' => $client->code,
            'client_name' => $client->name,
            'advisor_code' => $client->advisor?->code,
        ]);
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(['uploaded_by' => $user->getKey()]);
    }

    public function applied(string $receiptNumber = '1001'): static
    {
        return $this->state([
            'status' => ReceiptStatus::Applied,
            'receipt_number' => $receiptNumber,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'El valor no coincide con el extracto.'): static
    {
        return $this->state([
            'status' => ReceiptStatus::Rejected,
            'rejection_reason' => $reason,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }
}
