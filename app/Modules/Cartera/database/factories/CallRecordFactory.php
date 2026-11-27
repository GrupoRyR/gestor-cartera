<?php

namespace App\Modules\Cartera\Database\Factories;

use App\Models\User;
use App\Modules\Cartera\Domain\Calls\CallAgreement;
use App\Modules\Cartera\Domain\Calls\CallChannel;
use App\Modules\Cartera\Domain\Calls\CallOutcome;
use App\Modules\Cartera\Infrastructure\Persistence\CallRecord;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious answered phone calls to a Segurex client, made now by a new
 * user, with no agreement, follow-up or invoices.
 *
 * @extends Factory<CallRecord>
 */
final class CallRecordFactory extends Factory
{
    /**
     * @var class-string<CallRecord>
     */
    protected $model = CallRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company' => CompanyCode::SGX,
            'client_id' => fake()->unique()->numberBetween(1, 999_999),
            'user_id' => User::factory(),
            'occurred_at' => now(),
            'channel' => CallChannel::Call,
            'phone' => '3001234567',
            'contact_name' => 'Laura Martínez',
            'outcome' => CallOutcome::Answered,
            'notes' => null,
            'agreement' => CallAgreement::None,
        ];
    }

    public function forClient(int $clientId, CompanyCode $company = CompanyCode::SGX): static
    {
        return $this->state(['client_id' => $clientId, 'company' => $company]);
    }

    /**
     * The client asked to be called again on $day; the follow-up is open.
     */
    public function followUpOn(CarbonImmutable $day): static
    {
        return $this->state(['outcome' => CallOutcome::CallBack, 'follow_up_on' => $day->format('Y-m-d')]);
    }
}
