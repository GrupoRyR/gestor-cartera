<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Users sign in with Microsoft Entra ID, so they have no password.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'microsoft_id' => (string) Str::uuid(),
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => null,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the user has been deactivated from the Equipo screen.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the user has not signed in with Microsoft yet.
     */
    public function withoutMicrosoftId(): static
    {
        return $this->state(fn (array $attributes) => [
            'microsoft_id' => null,
        ]);
    }
}
