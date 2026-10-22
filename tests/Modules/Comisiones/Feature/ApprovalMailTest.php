<?php

use App\Modules\Comisiones\Database\Seeders\ComisionesPermissionSeeder;
use App\Modules\Comisiones\Infrastructure\Mail\SendApprovalMail;
use App\Modules\Comisiones\Infrastructure\Persistence\BudgetRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\ObrRecord;
use App\Modules\Comisiones\Infrastructure\Persistence\SettlementRecord;
use App\Modules\Core\Contracts\Data\MailAttachment;
use App\Modules\Core\Contracts\Data\OutgoingMail;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use App\Modules\Core\Contracts\Enums\Dataset;
use App\Modules\Core\Contracts\Exceptions\MailNotSent;
use App\Modules\Core\Contracts\MailSender;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\AuditEntry;
use Tests\Fakes\ContractFakes;
use Tests\Modules\Comisiones\Feature\Concerns\ComisionesUsers;
use Tests\Modules\Comisiones\Feature\Concerns\SapBatches;

/*
| Approving a settlement queues SendApprovalMail, which mails the
| consolidated report (PDF) of the approved version from the Segurex
| mailbox to comisiones.approval_mail.recipients. A mail that cannot go
| out never undoes the approval: it is audited and the period screen
| says so.
|
| July 2026: code 14 sells 69,915,134 on a budget of 77,241,857.
*/

beforeEach(function () {
    $this->seed(ComisionesPermissionSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-08-03 09:00:00', 'America/Bogota'));
    $this->fakes = ContractFakes::install($this->app);
    $this->analyst = ComisionesUsers::analyst();
    $this->approver = ComisionesUsers::approver();

    (new SapBatches($this->fakes->ingesta))->send(Dataset::Sales, [
        SapBatches::sale(['total' => '60000000.00']),
        SapBatches::sale(['linea' => 1, 'total' => '9915134.00']),
    ]);
    BudgetRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'valor' => '77241857.00', 'created_by' => $this->analyst->id]);

    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    Queue::fake();
});

/**
 * Runs the approval mail jobs the approval queued, as the worker would.
 */
function runApprovalMailJobs(): void
{
    foreach (Queue::pushed(SendApprovalMail::class) as $job) {
        app()->call([$job, 'handle']);
    }
}

it('queues one approval mail and sends nothing while approving', function () {
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertRedirect('/comisiones/periodos/2026-07')
        ->assertSessionHas('status', 'Aprobaste la versión 1 de la liquidación de 2026-07. El correo con el consolidado a marcela.sanabria@segurex.com y monica.pinzon@segurex.com se envía en segundo plano.')
        ->assertSessionMissing('error');

    Queue::assertPushed(SendApprovalMail::class, 1);
    Queue::assertPushed(SendApprovalMail::class, fn (SendApprovalMail $job): bool => $job->period === '2026-07'
        && $job->version === 1
        && $job->revision === 1
        && $job->approverId === $this->approver->id);
    $this->fakes->mail->assertNothingSent();
});

it('runs once: a retry never mails twice', function () {
    expect((new SendApprovalMail('2026-07', 1, 1, 1))->tries)->toBe(1);
});

it('mails the consolidated PDF from Segurex to the configured recipients when the job runs', function () {
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);
    runApprovalMailJobs();

    $this->fakes->mail->assertSent(times: 1);
    $mail = $this->fakes->mail->sent()[0];

    expect($mail->company)->toBe(CompanyCode::SGX)
        ->and($mail->to)->toBe(['marcela.sanabria@segurex.com', 'monica.pinzon@segurex.com'])
        ->and($mail->category)->toBe('comisiones_aprobacion')
        ->and($mail->subject)->toBe('Liquidación de comisiones aprobada – julio de 2026')
        ->and($mail->html)->toContain('julio de 2026')
        ->toContain('Versión 1 · revisión 1')
        ->toContain('Beto Ruiz')
        ->toContain('03/08/2026 09:00')
        ->toContain('Consolidado_Comisiones_2026-07_v1.pdf')
        ->and($mail->attachments)->toHaveCount(1);

    $attachment = $mail->attachments[0];

    expect($attachment)->toBeInstanceOf(MailAttachment::class)
        ->and($attachment->filename)->toBe('Consolidado_Comisiones_2026-07_v1.pdf')
        ->and($attachment->contentType)->toBe('application/pdf')
        ->and($attachment->content)->toStartWith('%PDF-');

    $this->fakes->audit->assertRecorded('comisiones.settlement.mailed', fn (AuditEntry $entry) => $entry->context['destinatarios'] === ['marcela.sanabria@segurex.com', 'monica.pinzon@segurex.com']);

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('Correo de aprobación enviado a marcela.sanabria@segurex.com y monica.pinzon@segurex.com el 03/08/2026 09:00.')
        ->assertDontSeeText('No se pudo enviar el correo de aprobación');
});

it('takes the recipients from comisiones.approval_mail.recipients', function () {
    config(['comisiones.approval_mail.recipients' => ['nomina@segurex.com']]);

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertSessionHas('status', 'Aprobaste la versión 1 de la liquidación de 2026-07. El correo con el consolidado a nomina@segurex.com se envía en segundo plano.');
    runApprovalMailJobs();

    $this->fakes->mail->assertSent(fn (OutgoingMail $mail): bool => $mail->to === ['nomina@segurex.com'], times: 1);
    $this->fakes->mail->assertSentTo('nomina@segurex.com');
});

it('keeps the settlement approved and shows the failure on the period screen when the mail cannot be sent', function () {
    $this->fakes->mail->failNextWith(new MailNotSent('Graph refused the mail.'));

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);
    runApprovalMailJobs();

    expect(SettlementRecord::query()->sole()->estado)->toBe('aprobada');
    $this->fakes->mail->assertNothingSent();
    $this->fakes->audit->assertRecorded('comisiones.settlement.approved');
    $this->fakes->audit->assertRecorded('comisiones.settlement.mail_failed', fn (AuditEntry $entry) => $entry->context['fallo'] === 'no_enviado');

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('No se pudo enviar el correo de aprobación (el buzón de Segurex no lo aceptó); descarga el consolidado y envíalo.')
        ->assertDontSeeText('Correo de aprobación enviado');
});

it('records the failure when no mail sender is bound', function () {
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);
    $this->app->forgetInstance(MailSender::class);
    unset($this->app[MailSender::class]);
    runApprovalMailJobs();

    expect(SettlementRecord::query()->sole()->estado)->toBe('aprobada');
    $this->fakes->audit->assertRecorded('comisiones.settlement.mail_failed', fn (AuditEntry $entry) => $entry->context['fallo'] === 'sin_mail_sender');

    $this->actingAs($this->approver)->get('/comisiones/periodos/2026-07')->assertOk()
        ->assertSeeText('No se pudo enviar el correo de aprobación (el envío de correo no está configurado); descarga el consolidado y envíalo.');
});

it('says so on approval and records the failure when no recipient is configured', function () {
    config(['comisiones.approval_mail.recipients' => []]);

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertSessionHas('status', 'Aprobaste la versión 1 de la liquidación de 2026-07. No hay destinatarios configurados para el correo con el consolidado: descárgalo y compártelo.');
    runApprovalMailJobs();

    expect(SettlementRecord::query()->sole()->estado)->toBe('aprobada');
    $this->fakes->mail->assertNothingSent();
    $this->fakes->audit->assertRecorded('comisiones.settlement.mail_failed', fn (AuditEntry $entry) => $entry->context['fallo'] === 'sin_destinatarios');
});

it('does not mail a version recalculated before the job runs', function () {
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);
    $this->actingAs($this->analyst)->post('/comisiones/periodos/2026-07/calcular');
    runApprovalMailJobs();

    expect(SettlementRecord::query()->sole()->revision)->toBe(2);
    $this->fakes->mail->assertNothingSent();
    $this->fakes->audit->assertRecorded('comisiones.settlement.mail_failed', fn (AuditEntry $entry) => $entry->context['fallo'] === 'recalculada');
});

it('queues nothing when the approval is refused', function () {
    ObrRecord::query()->create(['periodo' => '2026-07', 'slp_code' => 14, 'descripcion' => 'Escudos', 'valor_vendido' => '1.00', 'tasa' => '1', 'estado' => 'pendiente', 'created_by' => $this->approver->id]);

    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1])
        ->assertSessionHas('error', 'No puedes aprobar la liquidación de 2026-07: quien carga datos o cambia parámetros del período no la aprueba.');

    expect(SettlementRecord::query()->sole()->estado)->toBe('borrador');
    Queue::assertNothingPushed();
    $this->fakes->mail->assertNothingSent();
});

it('queues nothing more when the approved settlement is closed', function () {
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/aprobar', ['revision' => 1]);
    $this->actingAs($this->approver)->post('/comisiones/periodos/2026-07/cerrar')
        ->assertSessionHas('status', 'Cerraste el período 2026-07. La versión 1 quedó congelada.');

    expect(SettlementRecord::query()->sole()->estado)->toBe('cerrada');
    Queue::assertPushed(SendApprovalMail::class, 1);
});
