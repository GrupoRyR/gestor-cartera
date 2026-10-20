<?php

namespace Tests\Fakes;

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailRunResultsProvider;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Contracts\RecaudoProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Every Core contract replaced by its fake in the container.
 *
 *     $fakes = ContractFakes::install($this->app);
 *     $fakes->cartera->addClient(Make::clientDetail());
 *     // ... exercise the module ...
 *     $fakes->mail->assertSentTo('pagos@cliente.com');
 *
 * The fakes are wired together: the cartera, commitment and mailbox fakes
 * follow the current company, and the commitment and activity fakes know
 * the clients added to the cartera fake and honour its scope.
 */
final readonly class ContractFakes
{
    public function __construct(
        public FakeCurrentCompany $currentCompany,
        public FakeCarteraRepository $cartera,
        public FakeCompromisoService $compromisos,
        public FakeGestionLogger $gestiones,
        public FakeAuditLogger $audit,
        public FakeIngestaSink $ingesta,
        public FakeMailSender $mail,
        public FakeMailboxReader $mailbox,
        public FakeRecaudoProvider $recaudo,
        public FakeMailRunResultsProvider $mailRuns,
    ) {}

    /**
     * Binds a new set of fakes as the container instances of the contracts.
     */
    public static function install(Application $app): self
    {
        $currentCompany = new FakeCurrentCompany;
        $cartera = new FakeCarteraRepository($currentCompany);

        $fakes = new self(
            currentCompany: $currentCompany,
            cartera: $cartera,
            compromisos: new FakeCompromisoService($cartera, $currentCompany),
            gestiones: new FakeGestionLogger($cartera),
            audit: new FakeAuditLogger,
            ingesta: new FakeIngestaSink,
            mail: new FakeMailSender,
            mailbox: new FakeMailboxReader($currentCompany),
            recaudo: new FakeRecaudoProvider,
            mailRuns: new FakeMailRunResultsProvider,
        );

        $app->instance(CurrentCompany::class, $fakes->currentCompany);
        $app->instance(CarteraRepository::class, $fakes->cartera);
        $app->instance(CompromisoService::class, $fakes->compromisos);
        $app->instance(GestionLogger::class, $fakes->gestiones);
        $app->instance(AuditLogger::class, $fakes->audit);
        $app->instance(IngestaSink::class, $fakes->ingesta);
        $app->instance(MailSender::class, $fakes->mail);
        $app->instance(MailboxReader::class, $fakes->mailbox);
        $app->instance(RecaudoProvider::class, $fakes->recaudo);
        $app->instance(MailRunResultsProvider::class, $fakes->mailRuns);

        return $fakes;
    }
}
