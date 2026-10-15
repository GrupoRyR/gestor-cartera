<?php

use App\Modules\Core\Contracts\AuditLogger;
use App\Modules\Core\Contracts\CarteraRepository;
use App\Modules\Core\Contracts\CompromisoService;
use App\Modules\Core\Contracts\CurrentCompany;
use App\Modules\Core\Contracts\Data\NewActivity;
use App\Modules\Core\Contracts\Data\NewCommitment;
use App\Modules\Core\Contracts\Enums\ActivityType;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\GestionLogger;
use App\Modules\Core\Contracts\IngestaSink;
use App\Modules\Core\Contracts\MailboxReader;
use App\Modules\Core\Contracts\MailSender;
use App\Modules\Core\Contracts\RecaudoProvider;
use Carbon\CarbonImmutable;
use Tests\Fakes\ContractFakes;
use Tests\Fakes\Make;

it('binds every contract to its fake', function () {
    $fakes = ContractFakes::install($this->app);

    expect(app(CurrentCompany::class))->toBe($fakes->currentCompany)
        ->and(app(CarteraRepository::class))->toBe($fakes->cartera)
        ->and(app(CompromisoService::class))->toBe($fakes->compromisos)
        ->and(app(GestionLogger::class))->toBe($fakes->gestiones)
        ->and(app(AuditLogger::class))->toBe($fakes->audit)
        ->and(app(IngestaSink::class))->toBe($fakes->ingesta)
        ->and(app(MailSender::class))->toBe($fakes->mail)
        ->and(app(MailboxReader::class))->toBe($fakes->mailbox)
        ->and(app(RecaudoProvider::class))->toBe($fakes->recaudo);
});

it('shares the clients of the cartera fake and the active company', function () {
    $fakes = ContractFakes::install($this->app);
    $client = Make::client(name: 'Ferretería El Tornillo SAS');
    $foreign = Make::client(company: CompanyCode::SDC, name: 'Andina de Químicos SAS');
    $fakes->cartera->addClient(Make::clientDetail($client))->addClient(Make::clientDetail($foreign));

    $commitment = app(CompromisoService::class)->create(new NewCommitment($client->id, CarbonImmutable::parse('2026-10-30'), 100));
    $activity = app(GestionLogger::class)->log(new NewActivity($client->id, ActivityType::Note, 'Nota'));

    expect($commitment->clientName)->toBe('Ferretería El Tornillo SAS')
        ->and($activity->clientName)->toBe('Ferretería El Tornillo SAS')
        ->and(app(CarteraRepository::class)->client($foreign->id))->toBeNull();

    $fakes->currentCompany->switchTo(CompanyCode::SDC);

    expect(app(CarteraRepository::class)->client($foreign->id)?->summary->name)->toBe('Andina de Químicos SAS');
});
