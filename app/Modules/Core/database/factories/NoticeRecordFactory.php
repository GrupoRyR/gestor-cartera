<?php

namespace App\Modules\Core\Database\Factories;

use App\Models\User;
use App\Modules\Core\Infrastructure\Persistence\NoticeRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious in-app notices: unread and open, for a new user unless the test
 * passes user_id (or uses for()).
 *
 * @extends Factory<NoticeRecord>
 */
final class NoticeRecordFactory extends Factory
{
    /**
     * @var class-string<NoticeRecord>
     */
    protected $model = NoticeRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company' => 'SGX',
            'kind' => 'core.prueba',
            'title' => 'Tienes un aviso de prueba',
            'body' => null,
            'subject_type' => null,
            'subject_id' => null,
            'key' => null,
            'data' => null,
        ];
    }

    /**
     * Indicate that the recipient read it.
     */
    public function read(): static
    {
        return $this->state(fn (array $attributes) => ['read_at' => now()]);
    }

    /**
     * Indicate that what it asked for is done.
     */
    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => ['resolved_at' => now()]);
    }
}
