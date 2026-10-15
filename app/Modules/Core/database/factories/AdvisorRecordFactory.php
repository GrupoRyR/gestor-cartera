<?php

namespace App\Modules\Core\Database\Factories;

use App\Models\User;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\AdvisorRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictitious SAP sales employees: active, of Segurex, not linked to a user,
 * with a Colombian-looking name and an e-mail made from it.
 *
 * @extends Factory<AdvisorRecord>
 */
final class AdvisorRecordFactory extends Factory
{
    /**
     * @var class-string<AdvisorRecord>
     */
    protected $model = AdvisorRecord::class;

    private const array FIRST_NAMES = [
        'Andrés', 'Camilo', 'Carolina', 'Catalina', 'Diana', 'Felipe', 'Juliana', 'Natalia',
        'Jhon', 'Laura', 'Luisa', 'Mauricio', 'Paola', 'Santiago', 'Sebastián', 'Valentina',
    ];

    private const array LAST_NAMES = [
        'Arango', 'Cárdenas', 'Castaño', 'Duque', 'Gaviria', 'Londoño', 'Montoya', 'Ospina',
        'Quintero', 'Restrepo', 'Rincón', 'Salazar', 'Valencia', 'Vélez', 'Zapata', 'Zuluaga',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->randomElement(self::FIRST_NAMES);
        $lastName = fake()->randomElement(self::LAST_NAMES);
        $code = fake()->unique()->numberBetween(1, 99_999);

        return [
            'company' => CompanyCode::SGX,
            'code' => $code,
            'name' => "{$firstName} {$lastName}",
            // The code keeps two advisors with the same name apart.
            'email' => Str::slug("{$firstName} {$lastName}", '.')."{$code}@segurex.com",
            'active' => true,
            'user_id' => null,
        ];
    }

    /**
     * Indicate that the sales employee is inactive in SAP.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }

    /**
     * Indicate that the sales employee is linked to $user.
     */
    public function linkedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }
}
