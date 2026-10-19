<?php

namespace App\Modules\Bancos\Database\Seeders;

use App\Modules\Bancos\Domain\Movement\ImportSource;
use App\Modules\Bancos\Domain\Movement\MovementStatus;
use App\Modules\Bancos\Infrastructure\Persistence\BankLoad;
use App\Modules\Bancos\Infrastructure\Persistence\BankMovement;
use App\Modules\Core\Contracts\Enums\Bank;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Fictitious bank credits for local development: three weeks of the four
 * banks of both companies, with a mix of pending, applied and ignored, and the
 * Miami account in dollars. DatabaseSeeder runs it only with APP_ENV=local.
 * It does nothing when there are movements already.
 */
final class BancosDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (BankMovement::query()->exists()) {
            return;
        }

        $yesterday = CarbonImmutable::yesterday('America/Bogota');

        foreach (CompanyCode::cases() as $company) {
            foreach (Bank::cases() as $bank) {
                $load = BankLoad::query()->create([
                    'company' => $company,
                    'bank' => $bank,
                    'source' => ImportSource::Folder,
                    'file_name' => "extracto-demo-{$bank->value}.csv",
                ]);

                for ($back = 0; $back < 21; $back++) {
                    $date = $yesterday->subDays($back);

                    if ($date->isSunday()) {
                        continue;
                    }

                    for ($credit = fake()->numberBetween(0, 3); $credit > 0; $credit--) {
                        BankMovement::factory()
                            ->forCompany($company)
                            ->inBank($bank)
                            ->on($date)
                            ->create([...self::creditAttributes($bank, $back), 'load_id' => $load->id]);
                    }
                }

                $load->update(['inserted' => $load->movements()->count(), 'credits' => $load->movements()->count()]);
            }
        }
    }

    /**
     * Older credits are mostly applied; the latest ones wait for their receipt.
     *
     * @return array<string, mixed>
     */
    private static function creditAttributes(Bank $bank, int $daysBack): array
    {
        $status = match (true) {
            $daysBack > 5 => fake()->randomElement([MovementStatus::Applied, MovementStatus::Applied, MovementStatus::Applied, MovementStatus::Ignored]),
            default => fake()->randomElement([MovementStatus::Pending, MovementStatus::Pending, MovementStatus::Applied]),
        };

        return [
            'status' => $status,
            'receipt_number' => $status === MovementStatus::Applied ? (string) fake()->numberBetween(4000, 4999) : null,
            'currency' => $bank === Bank::Miami ? 'USD' : 'COP',
            'amount' => $bank === Bank::Miami ? fake()->numberBetween(500, 20_000).'.00' : fake()->numberBetween(80_000, 25_000_000).'.00',
        ];
    }
}
