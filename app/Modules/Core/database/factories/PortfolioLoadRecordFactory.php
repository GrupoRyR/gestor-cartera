<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\LoadSource;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious receivables snapshots: an empty robot load of Segurex, cut today.
 *
 * @extends Factory<PortfolioLoadRecord>
 */
final class PortfolioLoadRecordFactory extends Factory
{
    /**
     * @var class-string<PortfolioLoadRecord>
     */
    protected $model = PortfolioLoadRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company' => CompanyCode::SGX,
            'cutoff_date' => now()->toDateString(),
            'load_id' => fake()->unique()->numberBetween(1, 999_999),
            'source' => LoadSource::Robot,
            'invoice_count' => 0,
            'total' => '0.00',
            'overdue' => '0.00',
        ];
    }
}
