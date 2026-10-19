<?php

namespace Tests\Modules\Cobro\Support;

use App\Modules\Cobro\Infrastructure\Persistence\Exclusion;
use App\Modules\Core\Contracts\Data\ClientDetail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

/**
 * The cartera the run tests share, added to the cartera fake:
 *
 * - tornillo (SGX): two overdue invoices (47 and 18 days) and one not due
 *   yet; a primary and a second contact;
 * - andinas (SGX): one overdue invoice, no contact e-mail;
 * - excluido (SGX): one overdue invoice, in the exclusion list;
 * - alDia (SGX): only an invoice not due yet;
 * - quimicos (SDC): one overdue invoice.
 *
 * The snapshot cutoff is 2026-10-20.
 */
final class CobroPortfolio
{
    public ClientDetail $tornillo;

    public ClientDetail $andinas;

    public ClientDetail $excluido;

    public ClientDetail $alDia;

    public ClientDetail $quimicos;

    public static function seed(ContractFakes $fakes): self
    {
        $portfolio = new self;
        $cartera = $fakes->cartera;
        $cartera->cutoffDate = CarbonImmutable::parse('2026-10-20', 'America/Bogota');

        $portfolio->tornillo = Make::clientDetail(
            Make::client(name: 'Ferretería El Tornillo SAS', taxId: '900111222', balance: 4_500_000, overdue: 3_500_000, maxDaysOverdue: 47),
            contacts: [
                Make::contact(name: 'Laura Martínez', email: 'tesoreria@tornillo.example', isPrimary: true),
                Make::contact(name: 'Pedro Gómez', email: 'pagos@tornillo.example'),
            ],
        );
        $id = $portfolio->tornillo->summary->id;
        $cartera->addClient(
            $portfolio->tornillo,
            Make::invoice(clientId: $id, documentNumber: 'FV-1188', amount: 2_350_000, daysOverdue: 47),
            Make::invoice(clientId: $id, documentNumber: 'FV-1201', amount: 1_150_000, daysOverdue: 18),
            Make::invoice(clientId: $id, documentNumber: 'FV-1250', amount: 1_000_000, daysOverdue: -10),
        );

        $portfolio->andinas = Make::clientDetail(
            Make::client(name: 'Distribuciones Andinas SAS', balance: 800_000, overdue: 800_000, maxDaysOverdue: 9),
            contacts: [Make::contact(email: null, isPrimary: true)],
        );
        $cartera->addClient($portfolio->andinas, Make::invoice(clientId: $portfolio->andinas->summary->id, documentNumber: 'FV-1300', amount: 800_000, daysOverdue: 9));

        $portfolio->excluido = Make::clientDetail(
            Make::client(name: 'Comercializadora Excluida SAS', balance: 600_000, overdue: 600_000, maxDaysOverdue: 30),
            contacts: [Make::contact(email: 'cartera@excluida.example', isPrimary: true)],
        );
        $cartera->addClient($portfolio->excluido, Make::invoice(clientId: $portfolio->excluido->summary->id, documentNumber: 'FV-1400', amount: 600_000, daysOverdue: 30));

        Exclusion::query()->create([
            'company' => CompanyCode::SGX,
            'client_id' => $portfolio->excluido->summary->id,
            'client_code' => $portfolio->excluido->summary->code,
            'client_name' => $portfolio->excluido->summary->name,
            'reason' => 'Acuerdo de pago vigente',
        ]);

        $portfolio->alDia = Make::clientDetail(Make::client(name: 'Comercial Al Día SAS', balance: 500_000));
        $cartera->addClient($portfolio->alDia, Make::invoice(clientId: $portfolio->alDia->summary->id, amount: 500_000, daysOverdue: -5));

        $portfolio->quimicos = Make::clientDetail(
            Make::client(company: CompanyCode::SDC, name: 'Químicos del Norte SAS', balance: 700_000, overdue: 700_000, maxDaysOverdue: 12),
            contacts: [Make::contact(email: 'pagos@quimicos.example', isPrimary: true)],
        );
        $cartera->addClient($portfolio->quimicos, Make::invoice(company: CompanyCode::SDC, clientId: $portfolio->quimicos->summary->id, amount: 700_000, daysOverdue: 12));

        return $portfolio;
    }
}
