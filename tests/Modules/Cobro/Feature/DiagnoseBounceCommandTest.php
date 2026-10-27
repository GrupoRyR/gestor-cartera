<?php

use App\Modules\Cobro\Infrastructure\Persistence\InboundMessage;
use App\Modules\Cobro\Infrastructure\Persistence\MailboxCursor;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Cobro\Support\GraphFake;

const COBRO_DIAGNOSED = 'AAMkAGVmMDEzMTM4LTZmYWUtNDdkNC1hMDZiLTU1OGY5OTZhYmY4OABGAAAAAAAiQ8W967B7TKBjgx9rVEURBwAiIsqMbYjsT5e-T7KzowPTAAAAAAEMAAAiIsqMbYjsT5e-T7KzowPTAAAa_WLBAAA=';

/**
 * Graph's answer for the diagnosed message: the headers of $headersFixture,
 * the sender, the message class and, as bait, a body and a subject that
 * must never be printed.
 *
 * @return array<string, mixed>
 */
function cobroDiagnosedMessage(string $headersFixture, string $sender, ?string $class): array
{
    return array_replace(GraphFake::fixture($headersFixture), [
        'subject' => 'Asunto que no se imprime',
        'body' => ['contentType' => 'text', 'content' => 'Cuerpo que no se imprime'],
        'from' => ['emailAddress' => ['name' => 'Microsoft Outlook', 'address' => $sender]],
        'singleValueExtendedProperties' => $class === null ? [] : [['id' => 'String 0x1a', 'value' => $class]],
    ]);
}

it('prints what the bounce rule reads from our Exchange report and its decision', function () {
    $graph = GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/messages/*', cobroDiagnosedMessage(
        'message-headers-ndr.json',
        'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com',
        'REPORT.IPM.Note.NDR',
    ));

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'sgx', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('Clase del mensaje: REPORT.IPM.Note.NDR')
        ->expectsOutputToContain('X-MS-Exchange-Organization-AuthAs: Internal')
        ->expectsOutputToContain('Remitente: microsoftexchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com')
        ->expectsOutputToContain('Estructura DSN (multipart/report): sí')
        ->expectsOutputToContain('Decisión: rebote de nuestro Exchange')
        ->doesntExpectOutputToContain('no se imprime')
        ->assertSuccessful();

    [$request] = $graph->requests();
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    expect(rawurldecode((string) parse_url($request->url(), PHP_URL_PATH)))->toBe('/v1.0/users/segurex.info@segurex.com/messages/'.COBRO_DIAGNOSED)
        ->and($query)->toBe([
            '$select' => 'from,internetMessageHeaders',
            '$expand' => "singleValueExtendedProperties(\$filter=id eq 'String 0x001A')",
        ]);
});

it('prints the tenant domains among ours when they are configured', function () {
    config(['cobro.tenant_domains' => ['gruporyr.onmicrosoft.com']]);
    GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/messages/*', cobroDiagnosedMessage(
        'message-headers-ndr.json',
        'postmaster@gruporyr.onmicrosoft.com',
        'REPORT.IPM.Note.NDR',
    ));

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('Dominios propios: segurex.com, derivadoscol.com, gruporyr.onmicrosoft.com')
        ->expectsOutputToContain('Decisión: rebote de nuestro Exchange')
        ->assertSuccessful();
});

it('prints a forged report as a reply', function () {
    GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/messages/*', cobroDiagnosedMessage(
        'message-headers-forged.json',
        'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com',
        'IPM.Note',
    ));

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('Clase del mensaje: IPM.Note')
        ->expectsOutputToContain('X-MS-Exchange-Organization-AuthAs: Anonymous')
        ->expectsOutputToContain('Estructura DSN (multipart/report): no')
        ->expectsOutputToContain('Decisión: respuesta normal')
        ->doesntExpectOutputToContain('no se imprime')
        ->assertSuccessful();
});

it('prints another server\'s report as pending its quoted Message-ID', function () {
    GraphFake::install()->on('GET', 'users/derivados.info@derivadoscol.com/messages/*', cobroDiagnosedMessage(
        'message-headers-forged.json',
        'mailer-daemon@googlemail.com',
        'REPORT.IPM.Note.NDR',
    ));

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SDC', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('Decisión: rebote de otro servidor, solo si cita el Message-ID de un envío')
        ->assertSuccessful();
});

it('prints a missing message class', function () {
    GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/messages/*', cobroDiagnosedMessage(
        'message-headers-ndr.json',
        'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com',
        null,
    ));

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('Clase del mensaje: (sin dato)')
        ->expectsOutputToContain('Decisión: respuesta normal')
        ->assertSuccessful();
});

it('fails without detail when Graph does not return the message', function () {
    GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/messages/*', GraphFake::fixture('error-item-not-found.json'), 404);

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('Graph no devolvió el mensaje: HTTP 404.')
        ->assertFailed();
});

it('refuses an unknown company', function () {
    $graph = GraphFake::install();

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'XYZ', 'mensaje' => COBRO_DIAGNOSED])
        ->expectsOutputToContain('El buzón debe ser SGX o SDC.')
        ->assertFailed();

    expect($graph->requests())->toBe([]);
});

it('tries the delta query with the message class once', function () {
    $graph = GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/mailFolders/inbox/messages/delta', GraphFake::fixture('inbox-delta-page-1.json'));

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', '--probar-delta' => true])
        ->expectsOutputToContain('Graph aceptó la consulta delta con la clase del mensaje')
        ->doesntExpectOutputToContain('Facturas vencidas')
        ->assertSuccessful();

    [$request] = $graph->requests();
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    expect($graph->requests())->toHaveCount(1)
        ->and($query['$expand'] ?? null)->toBe("singleValueExtendedProperties(\$filter=id eq 'String 0x001A')")
        ->and($query)->toHaveKeys(['$select', '$filter', 'changeType'])
        ->and($request->header('Prefer'))->toBe(['odata.maxpagesize=1, outlook.body-content-type="text"']);
});

it('reports the status when Graph refuses the delta query', function () {
    GraphFake::install()->on('GET', 'users/segurex.info@segurex.com/mailFolders/inbox/messages/delta', ['error' => ['code' => 'BadRequest']], 400);

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', '--probar-delta' => true])
        ->expectsOutputToContain('Graph rechazó la consulta delta con la clase del mensaje: HTTP 400.')
        ->assertFailed();
});

it('asks for a message or the delta test', function () {
    GraphFake::install();

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX'])
        ->expectsOutputToContain('Indica el id de Graph del mensaje o usa --probar-delta.')
        ->assertFailed();
});

it('never writes to the database', function () {
    GraphFake::install()
        ->on('GET', 'users/segurex.info@segurex.com/mailFolders/inbox/messages/delta', GraphFake::fixture('inbox-delta-page-1.json'))
        ->on('GET', 'users/segurex.info@segurex.com/messages/*', cobroDiagnosedMessage(
            'message-headers-ndr.json',
            'MicrosoftExchange329e71ec88ae4615bbc36ab6ce41109e@segurex.com',
            'REPORT.IPM.Note.NDR',
        ));
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('cobro:diagnosticar-rebote', ['buzon' => 'SGX', 'mensaje' => COBRO_DIAGNOSED, '--probar-delta' => true])
        ->assertSuccessful();

    expect($writes)->toBe([])
        ->and(InboundMessage::query()->count())->toBe(0)
        ->and(MailboxCursor::query()->count())->toBe(0);
});
