<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\InvoiceRecord;
use App\Modules\Core\Infrastructure\Persistence\PortfolioLoadRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious open invoices of Segurex: due on the cutoff date, issued 30 days
 * before, and still owed in full. Each one gets its own snapshot and client
 * unless the test passes them with for().
 *
 * @extends Factory<InvoiceRecord>
 */
final class InvoiceRecordFactory extends Factory
{
    /**
     * @var class-string<InvoiceRecord>
     */
    protected $model = InvoiceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->numberBetween(100_000, 50_000_000).'.'.fake()->numerify('##');
        $dueAt = now()->startOfDay();

        return [
            'portfolio_load_id' => PortfolioLoadRecord::factory(),
            'company' => CompanyCode::SGX,
            'client_id' => ClientRecord::factory(),
            'document_number' => fake()->numerify('1#####'),
            // JDT1.TransType of an A/R invoice.
            'document_type' => '13',
            'installment' => null,
            'issued_at' => $dueAt->subDays(30)->toDateString(),
            'due_at' => $dueAt->toDateString(),
            'amount' => $amount,
            'balance' => $amount,
            'days_overdue' => 0,
            'advisor_code' => null,
        ];
    }
}
