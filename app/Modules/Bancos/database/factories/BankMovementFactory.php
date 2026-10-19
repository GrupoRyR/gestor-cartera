<?php

namespace App\Modules\Bancos\Database\Factories;

use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious pending credits of Segurex in Bancolombia, yesterday. The load
 * it belongs to is created with the same company and bank unless one is given.
 *
 * @extends Factory<BankMovement>
 */
final class BankMovementFactory extends Factory
{
    /**
     * @var class-string<BankMovement>
     */
    protected $model = BankMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'load_id' => fn (array $attributes): int => BankLoad::query()->create([
                'company' => $attributes['company'],
                'bank' => $attributes['bank'],
                'source' => ImportSource::Folder,
            ])->id,
            'company' => CompanyCode::SGX,
            'bank' => Bank::Bancolombia,
            'movement_date' => CarbonImmutable::yesterday('America/Bogota'),
            'amount' => fake()->numberBetween(50_000, 15_000_000).'.00',
            'currency' => 'COP',
            'description' => 'TRANSFERENCIA '.mb_strtoupper(fake()->company()),
            'reference' => (string) fake()->numberBetween(100000, 999999),
            'account' => null,
            'fingerprint' => fn (): string => hash('sha256', (string) fake()->unique()->uuid()),
            'status' => MovementStatus::Pending,
        ];
    }

    public function forCompany(CompanyCode $company): static
    {
        return $this->state(['company' => $company]);
    }

    public function inBank(Bank $bank): static
    {
        return $this->state(['bank' => $bank]);
    }

    public function on(CarbonImmutable|string $date): static
    {
        return $this->state(['movement_date' => $date]);
    }

    public function worth(string $amount): static
    {
        return $this->state(['amount' => $amount]);
    }

    public function applied(string $receipt = '1001'): static
    {
        return $this->state([
            'status' => MovementStatus::Applied,
            'receipt_number' => $receipt,
            'applied_at' => now(),
        ]);
    }

    public function ignored(): static
    {
        return $this->state(['status' => MovementStatus::Ignored]);
    }
}
