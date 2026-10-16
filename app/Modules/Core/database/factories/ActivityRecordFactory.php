<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ActivityRecord;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious entries of a client's log: a note written by the platform just
 * now, in the company of its client. Each one gets its own client unless the
 * test passes client_id.
 *
 * @extends Factory<ActivityRecord>
 */
final class ActivityRecordFactory extends Factory
{
    /**
     * @var class-string<ActivityRecord>
     */
    protected $model = ActivityRecord::class;

    private const array SUMMARIES = [
        'Llamada a tesorería', 'Revisión del estado de cuenta', 'Envío de la factura pendiente',
        'Seguimiento al pago', 'Confirmación de datos de contacto',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Before company: the company closure reads the created client.
            'client_id' => ClientRecord::factory(),
            'company' => fn (array $attributes): CompanyCode => ClientRecord::withTrashed()->findOrFail($attributes['client_id'])->company,
            'type' => ActivityType::Note,
            'summary' => fake()->randomElement(self::SUMMARIES),
            'details' => null,
            'result' => null,
            'user_id' => null,
            'occurred_at' => now(),
            'metadata' => null,
        ];
    }
}
