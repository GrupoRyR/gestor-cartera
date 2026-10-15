<?php

namespace App\Modules\Core\Database\Factories;

use App\Modules\Core\Contracts\Enums\ClientStatus;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Infrastructure\Persistence\ClientRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Fictitious clients of Segurex: a Colombian-looking company with a valid
 * NIT (DIAN check digit), a SAP code made from it, current and owing nothing,
 * without advisor or manager.
 *
 * @extends Factory<ClientRecord>
 */
final class ClientRecordFactory extends Factory
{
    /**
     * @var class-string<ClientRecord>
     */
    protected $model = ClientRecord::class;

    private const array KINDS = [
        'Agroinsumos', 'Comercializadora', 'Construcciones', 'Distribuciones', 'Ferretería',
        'Industrias', 'Inversiones', 'Suministros', 'Transportes', 'Montajes',
    ];

    private const array NAMES = [
        'Arango', 'Cárdenas', 'Castaño', 'Duque', 'El Tornillo', 'Gaviria', 'La Montaña', 'Londoño',
        'Montoya', 'Ospina', 'Quintero', 'Restrepo', 'Rincón', 'Salazar', 'Valencia', 'Zuluaga',
    ];

    private const array CITIES = [
        'Barranquilla', 'Bogotá', 'Bucaramanga', 'Cali', 'Cartagena', 'Envigado', 'Itagüí',
        'Manizales', 'Medellín', 'Pereira', 'Rionegro',
    ];

    private const array GROUPS = ['Agro', 'Constructoras', 'Distribuidores', 'Ferreterías', 'Industria'];

    private const array PAYMENT_TERMS = ['Contado', '30 días', '45 días', '60 días'];

    /**
     * Weights of the DIAN check digit, from the rightmost digit of the NIT.
     */
    private const array CHECK_DIGIT_WEIGHTS = [3, 7, 13, 17, 19, 23, 29, 37, 41, 43, 47, 53, 59, 67, 71];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Company NITs have nine digits and start with 8 or 9.
        $number = (string) fake()->unique()->numberBetween(800_000_000, 999_999_999);
        $kind = fake()->randomElement(self::KINDS);
        $name = fake()->randomElement(self::NAMES);

        return [
            'company' => CompanyCode::SGX,
            'code' => "C{$number}",
            'name' => "{$kind} {$name} S.A.S.",
            'tax_id' => $number.'-'.self::checkDigit($number),
            'group' => fake()->randomElement(self::GROUPS),
            'city' => fake()->randomElement(self::CITIES),
            'payment_terms' => fake()->randomElement(self::PAYMENT_TERMS),
            'credit_limit' => sprintf('%d.00', fake()->numberBetween(5, 300) * 1_000_000),
            'advisor_code' => null,
            'manager_user_id' => null,
            'status' => ClientStatus::Current,
            'balance' => '0.00',
            'overdue' => '0.00',
            'max_days_overdue' => 0,
        ];
    }

    /**
     * The DIAN check digit (dígito de verificación) of a NIT without it.
     */
    public static function checkDigit(string $number): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($number)) as $position => $digit) {
            $sum += (int) $digit * self::CHECK_DIGIT_WEIGHTS[$position];
        }

        $rest = $sum % 11;

        return $rest > 1 ? 11 - $rest : $rest;
    }
}
