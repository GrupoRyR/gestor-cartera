<?php

use App\Modules\Cobro\Circularizacion\CircularizationRun;
use App\Modules\Cobro\Domain\Runs\NoticeData;
use App\Modules\Cobro\Domain\Runs\RenderedMail;
use App\Modules\Core\Contracts\Data\BrandPalette;
use App\Modules\Core\Contracts\Data\Invoice;
use App\Modules\Core\Contracts\Enums\CompanyCode;
use Carbon\CarbonImmutable;
use Tests\Fakes\Make;
use Tests\Modules\Cobro\Support\VisibleText;

/*
 * The monthly circularization mail in the format each company sends today
 * (the Segurex and Derivados mails of the May 2026 close).
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-01 09:00:00', 'America/Bogota'));
});

function cobroCircInvoice(string $number, string $issued, string $due, int $days, float $balance, CompanyCode $company = CompanyCode::SGX): Invoice
{
    return Make::invoice(
        company: $company,
        documentNumber: $number,
        issuedAt: CarbonImmutable::parse($issued, 'America/Bogota'),
        dueAt: CarbonImmutable::parse($due, 'America/Bogota'),
        amount: $balance,
        daysOverdue: $days,
        advisorCode: 23,
    );
}

/**
 * A client of $company at the 2026-10-31 close: one invoice not due yet
 * (-29 days) and one overdue.
 */
function cobroCircNotice(CompanyCode $company, string $name = 'GOMEZ GOMEZ JOSE GREGORIO', ?string $cutoff = '2026-10-31'): NoticeData
{
    return new NoticeData(
        clientName: $name,
        taxId: '18004288',
        invoices: [
            cobroCircInvoice('8734', '2026-09-30', '2026-10-30', 1, 8_877_847, $company),
            cobroCircInvoice('8736', '2026-10-30', '2026-11-29', -29, 3_540_295, $company),
        ],
        cutoffDate: $cutoff === null ? null : CarbonImmutable::parse($cutoff, 'America/Bogota'),
        clientCode: 'CN0842',
        group: 'INDUSTRIAL',
        paymentTerms: '30 DIAS',
        advisorNames: [23 => 'MARIA DEL CARMEN PEÑA ARIZA'],
        advisorCode: 23,
    );
}

function cobroCircRender(CompanyCode $company, ?NoticeData $notice = null, bool $test = false): RenderedMail
{
    return app(CircularizationRun::class)->render(Make::company(code: $company), $notice ?? cobroCircNotice($company), $test);
}

/**
 * The header texts of the invoice table, in order.
 *
 * @return list<string>
 */
function cobroCircHeaders(string $html): array
{
    preg_match_all('/<th scope="col"[^>]*>(.*?)<\/th>/s', $html, $matches);

    return array_map(fn (string $header): string => trim(html_entity_decode($header)), $matches[1]);
}

it('titles the mail with the cutoff date in words, as each company writes it', function (CompanyCode $company, string $subject) {
    expect(cobroCircRender($company)->subject)->toBe($subject);
})->with([
    'Segurex' => [CompanyCode::SGX, 'Circularización de Cartera – Corte al 31 de octubre de 2026'],
    'Derivados, with «del» before the year' => [CompanyCode::SDC, 'Circularización de Cartera – Corte al 31 de octubre del 2026'],
]);

it('writes a dash for the cutoff date when the snapshot has none', function () {
    $mail = cobroCircRender(CompanyCode::SDC, cobroCircNotice(CompanyCode::SDC, cutoff: null));

    expect($mail->subject)->toBe('Circularización de Cartera – Corte al —')
        ->and(VisibleText::of($mail->html))->toContain('con corte al —.');
});

it('writes the Derivados texts, with the client and the cutoff in bold', function () {
    $mail = cobroCircRender(CompanyCode::SDC);
    $text = VisibleText::of($mail->html);

    expect($text)->toContain('Buen día Estimado cliente GOMEZ GOMEZ JOSE GREGORIO Cordial saludo, Con el fin de mantener actualizada nuestra información contable y dar cumplimiento a nuestros procesos de conciliación, nos permitimos circularizar el estado de su cartera con corte al 31 de octubre de 2026.')
        ->toContain('Agradecemos validar el saldo registrado y, en caso de presentarse alguna diferencia, remitirnos sus observaciones y/o soportes correspondientes dentro de los próximos cinco (5) días hábiles, a los correos cartera@derivadoscol.com y miguel.martinez@derivadoscol.com.')
        ->toContain('En caso de estar de acuerdo con la información suministrada, agradeceremos nos confirme su conformidad por este mismo medio. Su colaboración es fundamental para garantizar la correcta gestión y conciliación de nuestras cuentas.')
        ->toContain('Quedamos atentos a cualquier inquietud. Cordialmente, María Paula Alvarez Auxiliar de Crédito y Cobranza Celular: +57 3153654788 E-mail: cartera@derivadoscol.com')
        ->and($mail->html)->toContain('Estimado cliente <strong>GOMEZ GOMEZ JOSE GREGORIO</strong>')
        ->toContain('<strong>corte al 31 de octubre de 2026</strong>')
        ->toContain('<strong>cinco (5) días hábiles</strong>')
        ->toContain('<strong>Cordialmente,</strong>')
        ->toContain('<strong>María Paula Alvarez</strong><br>'."\n".'<strong>Auxiliar de Crédito y Cobranza</strong>');
});

it('writes the Segurex texts, with only the signature name in bold', function () {
    $mail = cobroCircRender(CompanyCode::SGX);
    $text = VisibleText::of($mail->html);

    expect($text)->toContain('Buen día Estimado cliente GOMEZ GOMEZ JOSE GREGORIO Cordial saludo, Con el fin de mantener actualizada nuestra información contable y dar cumplimiento a nuestros procesos de conciliación, nos permitimos circularizar el estado de su cartera con corte al 31 de octubre de 2026.')
        ->toContain('dentro de los próximos cinco (5) días hábiles, a los correos cartera@segurex.com, creditosgx@outlook.com y miguel.martinez@segurex.com.')
        ->toContain('Quedamos atentos a cualquier inquietud Cordialmente, María Paula Alvarez Auxiliar de Cartera')
        ->and($mail->html)->toContain('Estimado cliente GOMEZ GOMEZ JOSE GREGORIO')
        ->toContain('<strong>María Paula Alvarez</strong><br>'."\nAuxiliar de Cartera")
        ->not->toContain('<strong>GOMEZ GOMEZ JOSE GREGORIO</strong>')
        ->not->toContain('<strong>corte al')
        ->not->toContain('<strong>Cordialmente,</strong>');
});

it('lists the columns of each company in the order of its original, without Mora or Moneda', function (CompanyCode $company, array $headers) {
    $html = cobroCircRender($company)->html;

    expect(cobroCircHeaders($html))->toBe($headers)
        ->and(VisibleText::of($html))->not->toContain('Mora')->not->toContain('Moneda');
})->with([
    'Segurex' => [CompanyCode::SGX, ['Comercial', 'Cod.Cliente', 'Razón Social', 'NIT', 'Doc', 'Fecha Factura', 'Condiciones de Pago', 'Fecha Vencimiento', 'Dias', 'Total']],
    'Derivados' => [CompanyCode::SDC, ['NIT', 'Unidad de Negocio', 'Cod. Cliente', 'Razon Social', 'No. FV', 'Fecha Factura', 'Condiciones de pago', 'Fecha Vencimiento', 'dias', 'Total']],
]);

it('fills each row like the original, with signed days and the total', function () {
    $text = VisibleText::of(cobroCircRender(CompanyCode::SGX)->html);

    expect($text)->toContain('Segurex Latam Comercial')
        ->toContain('23 MARIA DEL CARMEN PEÑA ARIZA CN0842 GOMEZ GOMEZ JOSE GREGORIO 18004288 8734 30/09/2026 30 DIAS 30/10/2026 1 $ 8.877.847')
        ->toContain('8736 30/10/2026 30 DIAS 29/11/2026 -29 $ 3.540.295')
        ->toContain('$ 12.418.142');
});

it('puts the Derivados business unit after the NIT', function () {
    $text = VisibleText::of(cobroCircRender(CompanyCode::SDC)->html);

    expect($text)->toContain('Soluciones y Derivados NIT')
        ->toContain('18004288 INDUSTRIAL CN0842 GOMEZ GOMEZ JOSE GREGORIO 8736 30/10/2026 30 DIAS 29/11/2026 -29 $ 3.540.295');
});

it('colors the Segurex header with the brand button and the Derivados header grey', function () {
    expect(cobroCircRender(CompanyCode::SGX)->html)->toContain('background:'.BrandPalette::official(CompanyCode::SGX)->button.';color:#ffffff;')
        ->and(cobroCircRender(CompanyCode::SDC)->html)->toContain('background:#d9d9d9;color:#111111;')
        ->not->toContain('background:#fde2e2;');
});

it('adds the Segurex notice about payments after the cutoff, in bold red', function () {
    $html = cobroCircRender(CompanyCode::SGX)->html;

    expect($html)->toMatch('/<p data-aviso="cierre" style="[^"]*color:#b42318;font-weight:bold;">/')
        ->and(VisibleText::of($html))->toContain('"ACLARAMOS QUE ESTE ESTADO DE CARTERA CORRESPONDE AL CIERRE DEL 31 DE OCTUBRE. SI REALIZÓ EL PAGO DURANTE EL MES DE NOVIEMBRE, TENGA EN CUENTA QUE DICHO PAGO NO SE VE REFLEJADO EN ESTA CIRCULARIZACIÓN, YA QUE LA INFORMACIÓN CORRESPONDE EXCLUSIVAMENTE AL CIERRE DE OCTUBRE. AGRADECEMOS SU ATENCIÓN."');
});

it('places the Segurex notice between the table and the closing text', function () {
    $html = cobroCircRender(CompanyCode::SGX)->html;

    expect(strpos($html, '</table>'))->toBeLessThan(strpos($html, 'data-aviso="cierre"'))
        ->and(strpos($html, 'data-aviso="cierre"'))->toBeLessThan(strpos($html, 'Agradecemos validar el saldo'))
        ->and(strpos($html, 'Auxiliar de Cartera'))->toBeLessThan(strpos($html, 'data-pie="segurex"'));
});

it('names December and January around a year-end cutoff', function () {
    $text = VisibleText::of(cobroCircRender(CompanyCode::SGX, cobroCircNotice(CompanyCode::SGX, cutoff: '2026-12-31'))->html);

    expect($text)->toContain('AL CIERRE DEL 31 DE DICIEMBRE. SI REALIZÓ EL PAGO DURANTE EL MES DE ENERO,')
        ->toContain('AL CIERRE DE DICIEMBRE.');
});

it('leaves the Segurex notice out without a cutoff date', function () {
    expect(cobroCircRender(CompanyCode::SGX, cobroCircNotice(CompanyCode::SGX, cutoff: null))->html)->not->toContain('data-aviso="cierre"');
});

it('signs the Segurex mail with the company footer and its legal notice', function () {
    $html = cobroCircRender(CompanyCode::SGX)->html;
    $text = VisibleText::of($html);
    // The non-AA accent is never used as text: links take the button color.
    $palette = BrandPalette::official(CompanyCode::SGX);

    expect($text)->toContain('SEGUREX LATAM S.A.S. Calle 25D No. 95-45 Bogotá, D.C. - Colombia Móvil: +3153654788 PBX: +57 (60-1) 3713766 Ext. 141')
        ->toContain('cartera@segurex.com www.segurex.com Antes de imprimir, piense en su responsabilidad con el Medio Ambiente.')
        ->toContain('AVISO LEGAL: Este mensaje es solamente para la persona a la que va dirigido.')
        ->toContain('por favor hágalo saber inmediatamente al correo electrónico: ventas@segurex.com.')
        ->and($html)->toContain('<a href="mailto:cartera@segurex.com"')
        ->toContain('<a href="https://www.segurex.com" style="color:'.$palette->button.';font-weight:bold;">www.segurex.com</a>')
        ->toContain('<a href="mailto:ventas@segurex.com"')
        ->toContain('<strong>AVISO LEGAL:</strong>')
        ->not->toContain($palette->accent);
});

it('sends the Derivados mail without the notice or the footer', function () {
    $html = cobroCircRender(CompanyCode::SDC)->html;

    expect($html)->not->toContain('data-aviso="cierre"')
        ->not->toContain('data-pie="segurex"')
        ->not->toContain('ACLARAMOS')
        ->not->toContain('AVISO LEGAL');
});

it('reads like a plain mail, without the brand frame or a draft mark', function (CompanyCode $company) {
    $mail = cobroCircRender($company);

    expect($mail->subject)->not->toContain('[Borrador]')
        ->and($mail->html)->not->toContain('data-banner="borrador"')
        ->not->toContain('data-banner="prueba"')
        ->not->toContain('background:#f4f5f7');
})->with([CompanyCode::SGX, CompanyCode::SDC]);

it('marks a test send in the subject and the body', function () {
    $mail = cobroCircRender(CompanyCode::SGX, test: true);

    expect($mail->subject)->toBe('[Prueba] Circularización de Cartera – Corte al 31 de octubre de 2026')
        ->and($mail->html)->toContain('data-banner="prueba"')
        ->and($mail->cc)->toBe([]);
});

it('escapes every client value', function (CompanyCode $company) {
    $mail = cobroCircRender($company, cobroCircNotice($company, '<script>alert(1)</script> & Cía'));

    expect($mail->html)->not->toContain('<script>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Cía');
})->with([CompanyCode::SGX, CompanyCode::SDC]);
