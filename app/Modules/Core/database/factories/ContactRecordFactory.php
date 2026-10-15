<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use App\Modules\Core\Infrastructure\Persistence\ContactRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictitious client contacts from SAP: a Colombian-looking person with an
 * e-mail, a mobile number and a position, not the primary contact.
 *
 * @extends Factory<ContactRecord>
 */
final class ContactRecordFactory extends Factory
{
    /**
     * @var class-string<ContactRecord>
     */
    protected $model = ContactRecord::class;

    private const array FIRST_NAMES = [
        'Alejandra', 'Andrés', 'Beatriz', 'Carlos', 'Diana', 'Gloria', 'Hernán', 'Jorge',
        'Liliana', 'Marta', 'Óscar', 'Patricia', 'Ricardo', 'Sandra', 'Wilson', 'Yolanda',
    ];

    private const array LAST_NAMES = [
        'Agudelo', 'Bedoya', 'Cardona', 'Echeverri', 'Giraldo', 'Henao', 'Jaramillo', 'Mejía',
        'Muñoz', 'Orozco', 'Posada', 'Ramírez', 'Sierra', 'Toro', 'Uribe', 'Villegas',
    ];

    private const array POSITIONS = ['Cartera', 'Compras', 'Contabilidad', 'Gerencia', 'Tesorería'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(self::FIRST_NAMES).' '.fake()->randomElement(self::LAST_NAMES);

        return [
            'client_id' => ClientRecord::factory(),
            'name' => $name,
            'email' => Str::slug($name, '.').fake()->unique()->numberBetween(1, 99_999).'@example.com',
            'phone' => fake()->numerify('+57 3## ### ####'),
            'position' => fake()->randomElement(self::POSITIONS),
            'is_primary' => false,
            'source' => 'sap',
        ];
    }

    /**
     * Indicate that the contact is the client's primary contact.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_primary' => true,
        ]);
    }
}
